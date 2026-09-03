<?php declare(strict_types=1);
namespace VO\Admin;

use PDO; use VO\Audit\AuditService; use VO\Auth\AuthSession; use VO\Auth\CsrfService; use VO\Auth\PermissionGuard; use VO\Support\Template;

/**
 * Delivery configuration screen (spec D1/D4): branch-scoped zone CRUD and business-wide
 * delivery person registry with branch checkboxes. Everything behind `deliveries.manage`.
 * Zones referenced by carts are deactivated instead of deleted (orders keep a name snapshot,
 * so carts are the only live foreign key). All mutations: CSRF + audit + Spanish flash.
 */
final class DeliveryAdminController
{
    private const OK_MESSAGES = [
        'zone_created' => 'Zona de envío creada.', 'zone_updated' => 'Zona de envío actualizada.',
        'zone_toggled' => 'Estado de la zona actualizado.', 'zone_deleted' => 'Zona de envío eliminada.',
        'person_created' => 'Repartidor creado.', 'person_updated' => 'Repartidor actualizado.',
        'person_toggled' => 'Estado del repartidor actualizado.',
    ];
    private const ERR_MESSAGES = [
        'zone_invalid' => 'Datos de zona inválidos: el nombre y los términos son obligatorios y las tarifas deben ser enteros de centavos ≥ 0.',
        'zone_branch' => 'No podés administrar zonas de esa sucursal.',
        'zone_in_use' => 'La zona está referenciada por carritos activos: se desactivó en lugar de eliminarse.',
        'person_invalid' => 'Datos del repartidor inválidos: el nombre es obligatorio y debe tener al menos una sucursal.',
    ];

    public function __construct(private PDO $pdo, private AuthSession $sessions, private CsrfService $csrf, private Template $tpl, private AuditService $audit, private ?string $requestId) {}

    public function handle(string $method, string $path): never
    {
        $user = $this->user(); if ($user === null) $this->redirect('/admin/login');
        $guard = new PermissionGuard($this->pdo, (int)$user['id']);
        if (!$guard->requirePermission('deliveries.manage')) $this->deny((int)$user['id']);
        $userId = (int)$user['id'];
        if ($method === 'GET' && $path === '/admin/delivery') $this->page($user);
        if ($method === 'POST' && $path === '/admin/delivery/zonas') $this->createZone($user);
        if ($method === 'POST' && preg_match('#^/admin/delivery/zonas/(\d+)$#', $path, $m)) $this->updateZone($user, (int)$m[1]);
        if ($method === 'POST' && preg_match('#^/admin/delivery/zonas/(\d+)/estado$#', $path, $m)) $this->toggleZone($user, (int)$m[1]);
        if ($method === 'POST' && preg_match('#^/admin/delivery/zonas/(\d+)/eliminar$#', $path, $m)) $this->deleteZone($user, (int)$m[1]);
        if ($method === 'POST' && $path === '/admin/delivery/repartidores') $this->createPerson($user);
        if ($method === 'POST' && preg_match('#^/admin/delivery/repartidores/(\d+)$#', $path, $m)) $this->updatePerson($user, (int)$m[1]);
        if ($method === 'POST' && preg_match('#^/admin/delivery/repartidores/(\d+)/estado$#', $path, $m)) $this->togglePerson($user, (int)$m[1]);
        $this->notFound();
    }

    // ---- zones ----

    private function createZone(array $user): never
    {
        if (!$this->validCsrf($_POST['csrf'] ?? null)) $this->error(419, 'La solicitud venció. Volvé a intentar.');
        $data = $this->zoneData();
        if ($data === null) $this->error(422, self::ERR_MESSAGES['zone_invalid']);
        $branches = $this->branchIds((int)$user['id']);
        if (!in_array($data['branch_id'], $branches, true)) $this->error(422, self::ERR_MESSAGES['zone_branch']);
        $this->execute('INSERT INTO delivery_zones (branch_id,name,match_terms,customer_rate_cents,driver_payout_cents,is_active) VALUES (?,?,?,?,?,1)', [$data['branch_id'], $data['name'], $data['match_terms'], $data['customer_rate_cents'], $data['driver_payout_cents']]);
        $id = (int)$this->pdo->lastInsertId();
        $this->audit->append(['type' => 'user', 'id' => (int)$user['id']], 'delivery_zones.created', 'delivery_zone:' . $id, ['branch_id' => $data['branch_id']], $this->requestId);
        $this->redirect('/admin/delivery?ok=zone_created');
    }

    private function updateZone(array $user, int $zoneId): never
    {
        if (!$this->validCsrf($_POST['csrf'] ?? null)) $this->error(419, 'La solicitud venció. Volvé a intentar.');
        $zone = $this->scopedZone((int)$user['id'], $zoneId); if (!$zone) $this->notFound();
        $data = $this->zoneData((int)$zone['branch_id']); // edit keeps the zone's own branch
        if ($data === null) $this->error(422, self::ERR_MESSAGES['zone_invalid']);
        $this->execute('UPDATE delivery_zones SET branch_id=?,name=?,match_terms=?,customer_rate_cents=?,driver_payout_cents=? WHERE id=?', [$data['branch_id'], $data['name'], $data['match_terms'], $data['customer_rate_cents'], $data['driver_payout_cents'], $zoneId]);
        $this->audit->append(['type' => 'user', 'id' => (int)$user['id']], 'delivery_zones.updated', 'delivery_zone:' . $zoneId, ['branch_id' => (int)$zone['branch_id']], $this->requestId);
        $this->redirect('/admin/delivery?ok=zone_updated');
    }

    private function toggleZone(array $user, int $zoneId): never
    {
        if (!$this->validCsrf($_POST['csrf'] ?? null)) $this->error(419, 'La solicitud venció. Volvé a intentar.');
        $zone = $this->scopedZone((int)$user['id'], $zoneId); if (!$zone) $this->notFound();
        $active = (int)$zone['is_active'] === 1 ? 0 : 1;
        $this->execute('UPDATE delivery_zones SET is_active=? WHERE id=?', [$active, $zoneId]);
        $this->audit->append(['type' => 'user', 'id' => (int)$user['id']], 'delivery_zones.toggled', 'delivery_zone:' . $zoneId, ['is_active' => $active], $this->requestId);
        $this->redirect('/admin/delivery?ok=zone_toggled');
    }

    /** Delete only when no cart references the zone; otherwise deactivate (spec D1 FK policy). */
    private function deleteZone(array $user, int $zoneId): never
    {
        if (!$this->validCsrf($_POST['csrf'] ?? null)) $this->error(419, 'La solicitud venció. Volvé a intentar.');
        $zone = $this->scopedZone((int)$user['id'], $zoneId); if (!$zone) $this->notFound();
        if ($this->one('SELECT 1 FROM carts WHERE delivery_zone_id=? LIMIT 1', [$zoneId])) {
            $this->execute('UPDATE delivery_zones SET is_active=0 WHERE id=?', [$zoneId]);
            $this->audit->append(['type' => 'user', 'id' => (int)$user['id']], 'delivery_zones.toggled', 'delivery_zone:' . $zoneId, ['is_active' => 0, 'reason' => 'in_use'], $this->requestId);
            $this->redirect('/admin/delivery?err=zone_in_use');
        }
        $this->execute('DELETE FROM delivery_zones WHERE id=?', [$zoneId]);
        $this->audit->append(['type' => 'user', 'id' => (int)$user['id']], 'delivery_zones.deleted', 'delivery_zone:' . $zoneId, ['branch_id' => (int)$zone['branch_id']], $this->requestId);
        $this->redirect('/admin/delivery?ok=zone_deleted');
    }

    /** Validated zone payload: name/terms non-empty, integer rates >= 0. Without a posted branch_id the fallback (the zone's own branch) applies. */
    private function zoneData(?int $fallbackBranch = null): ?array
    {
        $name = trim((string)($_POST['name'] ?? ''));
        $terms = trim((string)($_POST['match_terms'] ?? ''));
        $rate = $this->rateCents($_POST['customer_rate_cents'] ?? null);
        $payout = $this->rateCents($_POST['driver_payout_cents'] ?? null);
        $branchId = (int)($_POST['branch_id'] ?? 0);
        if ($branchId <= 0 && $fallbackBranch !== null) $branchId = $fallbackBranch;
        if ($name === '' || $terms === '' || $rate === null || $payout === null || $branchId <= 0) return null;
        return ['branch_id' => $branchId, 'name' => $name, 'match_terms' => $terms, 'customer_rate_cents' => $rate, 'driver_payout_cents' => $payout];
    }

    private function rateCents(mixed $value): ?int
    {
        $s = trim((string)($value ?? ''));
        return preg_match('/^\d+$/', $s) === 1 ? (int)$s : null;
    }

    private function scopedZone(int $userId, int $zoneId): ?array
    {
        return $this->one('SELECT z.* FROM delivery_zones z JOIN user_branches ub ON ub.branch_id=z.branch_id AND ub.user_id=? WHERE z.id=? LIMIT 1', [$userId, $zoneId]);
    }

    // ---- persons ----

    private function createPerson(array $user): never
    {
        if (!$this->validCsrf($_POST['csrf'] ?? null)) $this->error(419, 'La solicitud venció. Volvé a intentar.');
        $data = $this->personData((int)$user['id']);
        if ($data === null) $this->error(422, self::ERR_MESSAGES['person_invalid']);
        $this->execute('INSERT INTO delivery_persons (business_id,name,phone,is_active) VALUES (?,?,?,1)', [(int)$user['business_id'], $data['name'], $data['phone']]);
        $id = (int)$this->pdo->lastInsertId();
        $this->syncBranches($id, $data['branches']);
        $this->audit->append(['type' => 'user', 'id' => (int)$user['id']], 'delivery_persons.created', 'delivery_person:' . $id, ['branches' => $data['branches']], $this->requestId);
        $this->redirect('/admin/delivery?ok=person_created');
    }

    private function updatePerson(array $user, int $personId): never
    {
        if (!$this->validCsrf($_POST['csrf'] ?? null)) $this->error(419, 'La solicitud venció. Volvé a intentar.');
        $person = $this->scopedPerson((int)$user['id'], $personId); if (!$person) $this->notFound();
        $data = $this->personData((int)$user['id']);
        if ($data === null) $this->error(422, self::ERR_MESSAGES['person_invalid']);
        $this->execute('UPDATE delivery_persons SET name=?,phone=? WHERE id=?', [$data['name'], $data['phone'], $personId]);
        $this->syncBranches($personId, $data['branches']);
        $this->audit->append(['type' => 'user', 'id' => (int)$user['id']], 'delivery_persons.updated', 'delivery_person:' . $personId, ['branches' => $data['branches']], $this->requestId);
        $this->redirect('/admin/delivery?ok=person_updated');
    }

    private function togglePerson(array $user, int $personId): never
    {
        if (!$this->validCsrf($_POST['csrf'] ?? null)) $this->error(419, 'La solicitud venció. Volvé a intentar.');
        $person = $this->scopedPerson((int)$user['id'], $personId); if (!$person) $this->notFound();
        $active = (int)$person['is_active'] === 1 ? 0 : 1;
        $this->execute('UPDATE delivery_persons SET is_active=? WHERE id=?', [$active, $personId]);
        $this->audit->append(['type' => 'user', 'id' => (int)$user['id']], 'delivery_persons.toggled', 'delivery_person:' . $personId, ['is_active' => $active], $this->requestId);
        $this->redirect('/admin/delivery?ok=person_toggled');
    }

    /** Validated person payload: non-empty name plus at least one branch inside the operator's scope. */
    private function personData(int $userId): ?array
    {
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $selected = array_map(static fn($v): int => (int)$v, (array)($_POST['branches'] ?? []));
        $branches = array_values(array_intersect($selected, $this->branchIds($userId)));
        if ($name === '' || $branches === []) return null;
        return ['name' => $name, 'phone' => $phone !== '' ? $phone : null, 'branches' => $branches];
    }

    /** Replace pivot links only inside the operator's branch scope; links outside the scope stay untouched. */
    private function syncBranches(int $personId, array $branchIds): void
    {
        $in = implode(',', array_fill(0, count($branchIds), '?'));
        $this->execute("DELETE FROM delivery_person_branches WHERE person_id=? AND branch_id IN ($in)", [$personId, ...$branchIds]);
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO delivery_person_branches (person_id,branch_id) VALUES (?,?)');
        foreach ($branchIds as $branchId) $stmt->execute([$personId, $branchId]);
    }

    private function scopedPerson(int $userId, int $personId): ?array
    {
        return $this->one('SELECT p.* FROM delivery_persons p WHERE p.id=? AND p.business_id=(SELECT business_id FROM users WHERE id=?) LIMIT 1', [$personId, $userId]);
    }

    // ---- page ----

    private function page(array $user): never
    {
        $userId = (int)$user['id'];
        $branches = $this->branches($userId);
        $zones = $this->rows('SELECT z.*, b.name branch_name FROM delivery_zones z JOIN branches b ON b.id=z.branch_id JOIN user_branches ub ON ub.branch_id=z.branch_id AND ub.user_id=? ORDER BY b.name, z.id', [$userId]);
        $persons = $this->rows('SELECT p.*, (SELECT GROUP_CONCAT(pb.branch_id) FROM delivery_person_branches pb WHERE pb.person_id=p.id) branch_ids FROM delivery_persons p WHERE p.business_id=? ORDER BY p.id', [(int)$user['business_id']]);
        [$flashOk, $flashErr] = [self::OK_MESSAGES[(string)($_GET['ok'] ?? '')] ?? null, self::ERR_MESSAGES[(string)($_GET['err'] ?? '')] ?? null];
        echo $this->tpl->render('delivery', ['csrf' => $this->token(), 'user' => $user, 'branches' => $branches, 'zones' => $zones, 'persons' => $persons, 'flashOk' => $flashOk, 'flashErr' => $flashErr]);
        exit;
    }

    private function branches(int $userId): array
    {
        return $this->rows('SELECT b.id, b.name FROM branches b JOIN user_branches ub ON ub.branch_id=b.id WHERE ub.user_id=? ORDER BY b.name', [$userId]);
    }

    private function branchIds(int $userId): array
    {
        return array_map(static fn(array $b): int => (int)$b['id'], $this->branches($userId));
    }

    // ---- shared plumbing (matches the other admin controllers) ----

    private function user(): ?array
    {
        $sid = $_SESSION['auth_sid'] ?? null;
        if (!is_string($sid) || $this->sessions->validate($sid) === null) return null;
        return $this->one('SELECT u.id, u.business_id, u.name, u.email, b.name business_name FROM users u JOIN businesses b ON b.id=u.business_id WHERE u.id=(SELECT user_id FROM auth_sessions WHERE sid_hash=? LIMIT 1) LIMIT 1', [AuthSession::hash($sid)]);
    }

    private function deny(int $userId): never
    {
        $this->audit->append(['type' => 'user', 'id' => $userId], 'authz.denied', null, ['permission' => 'deliveries.manage'], $this->requestId);
        $this->error(403, 'No tenés permiso para administrar repartos.');
    }

    private function token(): string
    {
        $t = (isset($_COOKIE['vo_csrf']) && preg_match('/^[a-f0-9]{64}$/', (string)$_COOKIE['vo_csrf']) === 1) ? (string)$_COOKIE['vo_csrf'] : $this->csrf->issue();
        $_SESSION['_csrf'] = $t;
        if (!headers_sent()) setcookie('vo_csrf', $t, ['path' => '/admin', 'httponly' => true, 'samesite' => 'Lax']);
        return $t;
    }

    private function validCsrf(mixed $token): bool
    {
        return $this->csrf->validate(is_string($token) ? $token : null) || (is_string($token) && isset($_COOKIE['vo_csrf']) && hash_equals((string)$_COOKIE['vo_csrf'], $token));
    }

    private function execute(string $sql, array $params = []): void { $stmt = $this->pdo->prepare($sql); $stmt->execute($params); }
    private function rows(string $sql, array $params = []): array { $stmt = $this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC); }
    private function one(string $sql, array $params = []): ?array { return $this->rows($sql, $params)[0] ?? null; }
    private function redirect(string $to): never { header('Location: ' . $to, true, 302); exit; }
    private function error(int $status, string $message): never { http_response_code($status); echo $this->tpl->render('catalog_error', ['csrf' => $this->token(), 'message' => $message]); exit; }
    private function notFound(): never { http_response_code(404); echo 'No encontrado'; exit; }
}
