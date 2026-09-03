<?php declare(strict_types=1);
namespace VO\Admin;

use DomainException; use InvalidArgumentException; use PDO; use Throwable; use VO\Audit\AuditService; use VO\Auth\AuthSession; use VO\Auth\CsrfService; use VO\Auth\PermissionGuard; use VO\Database\PdoConnection; use VO\Delivery\DeliveryRepository; use VO\Delivery\DeliveryService; use VO\Domain\InvalidTransition; use VO\Inventory\StockService; use VO\Notifications\NotificationService; use VO\Notifications\NotificationTransportFactory; use VO\Orders\OrderOperationsService; use VO\Orders\OrderRepository; use VO\Payments\PaymentRepository; use VO\Payments\PaymentService; use VO\Support\Template;

/**
 * Spanish operations board (GET /admin/operacion) and operator transition actions
 * (POST /admin/operacion/{id}/{accion}). All mutations go through OrderOperationsService
 * (order transitions) or DeliveryService (delivery lifecycle: asignar/reasignar/retirar/
 * entregar/fallar). Shared flash + delivery-panel helpers are reused by the orders detail page.
 */
final class OperationsAdminController
{
    /** accion slug => [target status, seeded permission key, Spanish label]. */
    public const ACTIONS = [
        'aceptar' => ['accepted', 'orders.accept', 'Aceptar'],
        'rechazar' => ['rejected', 'orders.reject', 'Rechazar'],
        'preparar' => ['in_progress', 'orders.prepare', 'Preparar'],
        'listo' => ['ready', 'orders.mark_ready', 'Marcar listo'],
        'completar' => ['completed', 'orders.prepare', 'Completar'],
        'cancelar' => ['cancelled', 'orders.cancel', 'Cancelar'],
    ];
    /** Delivery lifecycle actions, keyed by slug; routes carry the ORDER id like the rest of the board. */
    public const DELIVERY_ACTIONS = ['asignar', 'reasignar', 'retirar', 'entregar', 'fallar'];
    private const DELIVERY_PERMISSIONS = [
        'asignar' => 'deliveries.assign', 'reasignar' => 'deliveries.reassign',
        'retirar' => 'deliveries.assign', 'entregar' => 'deliveries.assign', 'fallar' => 'deliveries.assign',
    ];
    public const DELIVERY_STATE_LABELS = ['pending' => 'Pendiente', 'assigned' => 'Asignado', 'picked_up' => 'Retirado', 'delivered' => 'Entregado', 'failed' => 'Falló', 'cancelled' => 'Cancelado'];
    private const OK_MESSAGES = ['aceptar' => 'Pedido aceptado.', 'rechazar' => 'Pedido rechazado.', 'preparar' => 'Pedido en preparación.', 'listo' => 'Pedido marcado como listo.', 'completar' => 'Pedido completado.', 'cancelar' => 'Pedido cancelado.', 'asignar' => 'Repartidor asignado.', 'reasignar' => 'Repartidor reasignado.', 'retirar' => 'Pedido retirado por el repartidor.', 'entregar' => 'Pedido entregado.', 'fallar' => 'Entrega marcada como fallida.'];
    private const FLASH_ERRORS = [
        'invalid' => 'No se pudo aplicar la acción: el pedido cambió de estado. Actualizá y volvé a intentar.',
        'permission' => 'No tenés permiso para esa acción.',
        'pin' => 'PIN incorrecto. Verificá el código del cliente y volvé a intentar.',
        'pinmax' => 'Se agotaron los intentos de PIN: la entrega quedó marcada como fallida.',
        'notready' => 'El pedido todavía no está listo para entregar.',
        'person' => 'El repartidor no está disponible para esta sucursal.',
        'reason' => 'El motivo es obligatorio.',
    ];
    private const SECTIONS = ['pending' => 'Pendiente', 'change_proposed' => 'Cambio propuesto', 'accepted' => 'Aceptado', 'in_progress' => 'En preparación', 'ready' => 'Listo'];
    private const BOARD_STATUSES = ['pending', 'change_proposed', 'accepted', 'in_progress', 'ready'];

    public function __construct(private PDO $pdo, private AuthSession $sessions, private CsrfService $csrf, private Template $tpl, private \VO\Audit\AuditService $audit, private ?string $requestId) {}

    public function handle(string $method, string $path): never
    {
        $user = $this->user(); if ($user === null) $this->redirect('/admin/login');
        $guard = new PermissionGuard($this->pdo, (int)$user['id']);
        if ($method === 'GET' && $path === '/admin/operacion') { if (!$guard->requirePermission('orders.view')) $this->deny((int)$user['id'], 'orders.view'); $this->board($user, $guard); }
        $acciones = implode('|', array_merge(array_keys(self::ACTIONS), self::DELIVERY_ACTIONS));
        if ($method === 'POST' && preg_match('#^/admin/operacion/(\d+)/(' . $acciones . ')$#', $path, $m) === 1) $this->action($guard, $user, (int)$m[1], $m[2]);
        $this->notFound();
    }

    /** Legal next actions for a status, each with its seeded permission key. Shared with orders_detail. */
    public static function actionsFor(string $status): array
    {
        $legal = ['pending' => ['aceptar', 'rechazar', 'cancelar'], 'change_proposed' => ['aceptar', 'rechazar', 'cancelar'], 'accepted' => ['preparar', 'cancelar'], 'in_progress' => ['listo', 'cancelar'], 'ready' => ['completar', 'cancelar']][$status] ?? [];
        $out = [];
        foreach ($legal as $accion) { [$to, $permission, $label] = self::ACTIONS[$accion]; $out[] = ['accion' => $accion, 'to' => $to, 'permission' => $permission, 'label' => $label]; }
        return $out;
    }

    /**
     * Which delivery controls are legal for this delivery row, order status, and operator
     * permissions (spec D6–D8: assign pending only, reassign assigned only, deliver picked_up
     * + ready order, fail assigned/picked_up). Shared by board and orders detail.
     */
    public static function deliveryPanel(array $delivery, string $orderStatus, bool $assign, bool $reassign): array
    {
        $state = (string)$delivery['state'];
        $assignable = in_array($orderStatus, ['accepted', 'in_progress', 'ready'], true);
        return [
            'state' => $state,
            'state_label' => self::DELIVERY_STATE_LABELS[$state] ?? $state,
            'assign' => $state === 'pending' && $assignable && $assign,
            'reassign' => $state === 'assigned' && $assignable && $reassign,
            'pickup' => $state === 'assigned' && $assign,
            'deliver' => $state === 'picked_up' && $orderStatus === 'ready' && $assign,
            'fail' => in_array($state, ['assigned', 'picked_up'], true) && $assign,
        ];
    }

    /** Spanish flash pair for the ok/err redirect codes. Shared with orders_detail. */
    public static function flashFor(string $okKey, string $errKey): array
    {
        return [self::OK_MESSAGES[$okKey] ?? null, self::FLASH_ERRORS[$errKey] ?? null];
    }

    private function board(array $user, PermissionGuard $guard): never
    {
        try { $this->operations((int)$user['business_id'])->expireStaleLazy(); } catch (Throwable) { /* sweep is best-effort: never block the board */ }
        try { $this->notifications((int)$user['business_id'])->dispatchPendingLazy((int)$user['business_id']); } catch (Throwable) { /* notification sweep is best-effort too (spec N3) */ }
        $branchId = isset($_GET['branch_id']) && (int)$_GET['branch_id'] > 0 ? (int)$_GET['branch_id'] : null;
        $auto = (string)($_GET['auto'] ?? '') === '1';
        $orders = array_fill_keys(self::BOARD_STATUSES, []);
        $rows = (new OrderRepository($this->connection()))->boardRows((int)$user['id'], $branchId);
        foreach ($rows as $row) {
            $status = (string)$row['status'];
            if (isset($orders[$status])) $orders[$status][] = $row + ['age' => $this->age((string)$row['created_at'])];
        }
        $actions = [];
        foreach (self::BOARD_STATUSES as $status) $actions[$status] = array_values(array_filter(self::actionsFor($status), static fn(array $a): bool => $guard->requirePermission($a['permission'])));
        $deliveries = $this->deliveryContext($rows, $guard, static fn(array $row): string => (string)$row['status']);
        $filter = ['branch_id' => $branchId];
        $refreshUrl = '/admin/operacion' . ($branchId !== null ? '?branch_id=' . $branchId : '');
        [$flashOk, $flashErr] = self::flashFor((string)($_GET['ok'] ?? ''), (string)($_GET['err'] ?? ''));
        echo $this->tpl->render('operacion', [
            'csrf' => $this->token(), 'user' => $user, 'sections' => self::SECTIONS, 'orders' => $orders, 'actions' => $actions,
            'branches' => $this->branches((int)$user['id']), 'branchId' => $branchId, 'auto' => $auto,
            'deliveries' => $deliveries, 'flashOk' => $flashOk, 'flashErr' => $flashErr,
            'refreshUrl' => $refreshUrl, 'autoOnUrl' => $refreshUrl . (str_contains($refreshUrl, '?') ? '&' : '?') . 'auto=1',
        ]);
        exit;
    }

    /** Delivery panel context per order id for the visible rows (row + persons + permission-gated panel). */
    private function deliveryContext(array $orderRows, PermissionGuard $guard, ?\Closure $statusOf = null): array
    {
        $repo = new DeliveryRepository($this->connection());
        $orderIds = array_map(static fn(array $row): int => (int)$row['id'], $orderRows);
        $deliveries = $repo->forOrderIds($orderIds);
        if ($deliveries === []) return [];
        $assign = $guard->requirePermission('deliveries.assign');
        $reassign = $guard->requirePermission('deliveries.reassign');
        $personsByBranch = [];
        $out = [];
        foreach ($orderRows as $row) {
            $orderId = (int)$row['id'];
            $delivery = $deliveries[$orderId] ?? null;
            if (!$delivery) continue;
            $branchId = (int)$row['branch_id'];
            $personsByBranch[$branchId] ??= $repo->personsForBranch($branchId);
            $panel = self::deliveryPanel($delivery, $statusOf !== null ? $statusOf($row) : (string)$row['status'], $assign, $reassign);
            $person = $delivery['delivery_person_id'] !== null ? $repo->person((int)$delivery['delivery_person_id']) : null;
            $out[$orderId] = ['delivery' => $delivery, 'panel' => $panel, 'persons' => $personsByBranch[$branchId],
                'person_name' => (string)($person['name'] ?? '')];
        }
        return $out;
    }

    private function action(PermissionGuard $guard, array $user, int $orderId, string $accion): never
    {
        if (in_array($accion, self::DELIVERY_ACTIONS, true)) $this->deliveryAction($guard, $user, $orderId, $accion);
        [$to, $permission] = self::ACTIONS[$accion];
        if (!$this->validCsrf($_POST['csrf'] ?? null)) $this->error(419, 'La solicitud venció. Volvé a intentar.');
        if (!$guard->requirePermission($permission)) $this->deny((int)$user['id'], $permission, $orderId);
        $order = $this->scoped((int)$user['id'], $orderId);
        if (!$order) $this->notFound();
        $reason = isset($_POST['reason']) && trim((string)$_POST['reason']) !== '' ? trim((string)$_POST['reason']) : null;
        if ($to === 'cancelled' && $reason === null) $this->error(422, 'El motivo es obligatorio para cancelar un pedido.');
        $back = ($_POST['back'] ?? '') === 'detail' ? '/admin/pedidos/' . $orderId : '/admin/operacion';
        $branchId = isset($_POST['branch_id']) && (int)$_POST['branch_id'] > 0 ? (int)$_POST['branch_id'] : null;
        $query = array_filter(['branch_id' => $back === '/admin/operacion' ? $branchId : null, 'auto' => $back === '/admin/operacion' && (string)($_POST['auto'] ?? '') === '1' ? '1' : null], static fn(mixed $v): bool => $v !== null);
        $query['ok'] = $accion;
        try { $this->operations((int)$user['business_id'])->transition($orderId, $to, (int)$user['id'], $reason); }
        catch (Throwable) { $query = ['err' => 'invalid']; }
        $this->redirect($back . '?' . http_build_query($query));
    }

    /** Delivery lifecycle POSTs (asignar/reasignar/retirar/entregar/fallar) via DeliveryService. */
    private function deliveryAction(PermissionGuard $guard, array $user, int $orderId, string $accion): never
    {
        $permission = self::DELIVERY_PERMISSIONS[$accion];
        if (!$this->validCsrf($_POST['csrf'] ?? null)) $this->error(419, 'La solicitud venció. Volvé a intentar.');
        if (!$guard->requirePermission($permission)) $this->deny((int)$user['id'], $permission, $orderId);
        $order = $this->scoped((int)$user['id'], $orderId);
        if (!$order) $this->notFound();
        $delivery = (new DeliveryRepository($this->connection()))->findByOrderId($orderId);
        if (!$delivery) $this->notFound();
        $back = ($_POST['back'] ?? '') === 'detail' ? '/admin/pedidos/' . $orderId : '/admin/operacion';
        $branchId = isset($_POST['branch_id']) && (int)$_POST['branch_id'] > 0 ? (int)$_POST['branch_id'] : null;
        $query = array_filter(['branch_id' => $back === '/admin/operacion' ? $branchId : null, 'auto' => $back === '/admin/operacion' && (string)($_POST['auto'] ?? '') === '1' ? '1' : null], static fn(mixed $v): bool => $v !== null);
        $query['ok'] = $accion;
        $operator = (int)$user['id'];
        $deliveryId = (int)$delivery['id'];
        try {
            $service = $this->deliveries((int)$user['business_id']);
            match ($accion) {
                'asignar' => $service->assign($deliveryId, $this->personId(), $operator),
                'reasignar' => $service->reassign($deliveryId, $this->personId(), $operator),
                'retirar' => $service->markPickedUp($deliveryId, $operator),
                'entregar' => $service->deliver($deliveryId, trim((string)($_POST['pin'] ?? '')), $operator),
                'fallar' => $service->fail($deliveryId, trim((string)($_POST['reason'] ?? '')), $operator),
            };
        } catch (DomainException $e) {
            $query = ['err' => ['PERMISSION_DENIED' => 'permission', 'ORDER_NOT_READY' => 'notready', 'PIN_INVALID' => 'pin',
                'PIN_EXHAUSTED' => 'pinmax', 'PERSON_NOT_AVAILABLE' => 'person', 'FAIL_REASON_REQUIRED' => 'reason'][(string)$e->getMessage()] ?? 'invalid'];
        } catch (InvalidArgumentException) { $query = ['err' => 'reason']; }
        catch (InvalidTransition) { $query = ['err' => 'invalid']; }
        catch (Throwable) { $query = ['err' => 'invalid']; }
        $this->redirect($back . '?' . http_build_query($query));
    }

    private function personId(): int { return isset($_POST['person_id']) && (int)$_POST['person_id'] > 0 ? (int)$_POST['person_id'] : 0; }

    private function scoped(int $userId, int $orderId): ?array { $r = $this->rows('SELECT o.* FROM orders o JOIN user_branches ub ON ub.branch_id=o.branch_id AND ub.user_id=? WHERE o.id=? LIMIT 1', [$userId, $orderId]); return $r[0] ?? null; }
    private function branches(int $userId): array { return $this->rows('SELECT b.id, b.name FROM branches b JOIN user_branches ub ON ub.branch_id=b.id WHERE ub.user_id=? ORDER BY b.name', [$userId]); }
    private function age(string $createdAt): string { $s = max(0, time() - (int)strtotime($createdAt)); $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); return $h > 0 ? $h . ' h ' . $m . ' m' : $m . ' m'; }

    private function operations(int $businessId): OrderOperationsService
    {
        $db = $this->connection(); $notifications = $this->notifications($businessId);
        return new OrderOperationsService($db, new OrderRepository($db), new StockService($db), new PaymentRepository($db), new PaymentService($db, new PaymentRepository($db), new OrderRepository($db), $this->audit, $this->requestId, null, null, $notifications), $this->audit, $this->requestId, new DeliveryRepository($db), $notifications);
    }
    private function deliveries(int $businessId): DeliveryService
    {
        $db = $this->connection();
        return new DeliveryService($db, new DeliveryRepository($db), $this->audit, $this->requestId, $this->notifications($businessId));
    }
    /** Notification service for the board read point; transports built from business settings (null = unconfigured channel). */
    private function notifications(int $businessId): NotificationService
    {
        return NotificationTransportFactory::service($this->pdo, $businessId);
    }
    private function connection(): PdoConnection { return new PdoConnection('', factory: fn() => $this->pdo); }

    private function user(): ?array { $sid = $_SESSION['auth_sid'] ?? null; if (!is_string($sid) || $this->sessions->validate($sid) === null) return null; return $this->one('SELECT u.id, u.business_id, u.name, u.email, b.name business_name FROM users u JOIN businesses b ON b.id=u.business_id WHERE u.id=(SELECT user_id FROM auth_sessions WHERE sid_hash=? LIMIT 1) LIMIT 1', [AuthSession::hash($sid)]); }
    private function deny(int $userId, string $permission, ?int $orderId = null): never { $this->audit->append(['type' => 'user', 'id' => $userId], 'authz.denied', $orderId !== null ? 'order:' . $orderId : null, ['permission' => $permission], $this->requestId); $this->error(403, 'No tenés permiso para esta acción.'); }
    private function token(): string { $t = (isset($_COOKIE['vo_csrf']) && preg_match('/^[a-f0-9]{64}$/', (string)$_COOKIE['vo_csrf']) === 1) ? (string)$_COOKIE['vo_csrf'] : $this->csrf->issue(); $_SESSION['_csrf'] = $t; if (!headers_sent()) setcookie('vo_csrf', $t, ['path' => '/admin', 'httponly' => true, 'samesite' => 'Lax']); return $t; }
    private function validCsrf(mixed $token): bool { return $this->csrf->validate(is_string($token) ? $token : null) || (is_string($token) && isset($_COOKIE['vo_csrf']) && hash_equals((string)$_COOKIE['vo_csrf'], $token)); }
    private function rows(string $sql, array $p = []): array { $s = $this->pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function one(string $sql, array $p = []): ?array { $r = $this->rows($sql, $p); return $r[0] ?? null; }
    private function redirect(string $to): never { header('Location: ' . $to, true, 302); exit; }
    private function error(int $status, string $message): never { http_response_code($status); echo $this->tpl->render('catalog_error', ['csrf' => $this->token(), 'message' => $message]); exit; }
    private function notFound(): never { http_response_code(404); echo 'No encontrado'; exit; }
}
