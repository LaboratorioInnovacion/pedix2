<?php declare(strict_types=1);
namespace VO\Admin;

use PDO;
use Throwable;
use VO\Audit\AuditService;
use VO\Auth\AuthSession;
use VO\Auth\CsrfService;
use VO\Auth\LoginService;
use VO\Http\Request;
use VO\Database\PdoConnection;
use VO\Notifications\NotificationTransportFactory;
use VO\Reports\ReportsRepository;
use VO\Support\Template;

final class AdminController
{
    private PDO $pdo;
    private AuthSession $sessions;
    private CsrfService $csrf;
    private Template $tpl;
    private AuditService $audit;
    private ?string $requestId;

    public function __construct(private string $root, ?string $requestId = null)
    {
        $this->pdo = $this->pdo();
        $this->sessions = new AuthSession($this->pdo);
        $this->csrf = new CsrfService();
        $this->tpl = new Template($root . '/api/app/Admin/templates');
        $this->audit = new AuditService($this->pdo);
        $this->requestId = $requestId;
    }

    public static function startSession(): void
    {
        AuthSession::configureCookie((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'));
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    }

    public function handle(): void
    {
        $this->requestId ??= Request::newRequestId();
        self::startSession();
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH) ?: '/admin/';
        if ($method === 'GET' && $path === '/admin/login') $this->login();
        if ($method === 'POST' && $path === '/admin/login') $this->loginPost();
        if ($this->isCatalogPath($path)) (new CatalogAdminController($this->pdo, $this->sessions, $this->csrf, $this->tpl, $this->audit, $this->requestId))->handle($method, $path);
        if ($this->isPromotionsPath($path)) (new PromotionsAdminController($this->pdo, $this->sessions, $this->csrf, $this->tpl, $this->audit, $this->requestId))->handle($method, $path);
        if ($this->isOrdersPath($path)) (new OrdersAdminController($this->pdo, $this->sessions, $this->csrf, $this->tpl, $this->audit, $this->requestId))->handle($method, $path);
        if ($this->isOperationsPath($path)) (new OperationsAdminController($this->pdo, $this->sessions, $this->csrf, $this->tpl, $this->audit, $this->requestId))->handle($method, $path);
        if ($this->isDeliveryPath($path)) (new DeliveryAdminController($this->pdo, $this->sessions, $this->csrf, $this->tpl, $this->audit, $this->requestId))->handle($method, $path);
        if ($this->isPaymentsPath($path)) (new PaymentsAdminController($this->pdo,$this->sessions,$this->csrf,$this->tpl,$this->audit,$this->requestId,getenv('VO_STORAGE_PATH') ?: $this->root.'/api/storage'))->handle($method,$path);
        if ($path === '/admin/configuracion') (new SettingsAdminController($this->pdo,$this->sessions,$this->csrf,$this->tpl,$this->audit,$this->requestId))->handle($method);
        if ($path === '/admin/notificaciones') (new NotificationsAdminController($this->pdo,$this->sessions,$this->csrf,$this->tpl,$this->audit,$this->requestId))->handle($method);
        if ($this->isReportsPath($path)) (new ReportsAdminController($this->pdo, $this->sessions, $this->csrf, $this->tpl, $this->audit, $this->requestId))->handle($method, $path);
        if ($method === 'GET' && $path === '/admin/') $this->dashboard();
        if ($method === 'POST' && $path === '/admin/logout') $this->logout();
        http_response_code(404); echo 'No encontrado'; exit;
    }

    private function login(?string $error = null): never
    {
        echo $this->tpl->render('login', ['csrf' => $this->csrf(), 'error' => $error]);
        exit;
    }

    private function loginPost(): never
    {
        if (!$this->validCsrf($_POST['csrf'] ?? null)) $this->error('La solicitud venció. Volvé a intentar.', 419);
        $audit = function (array $e): void {
            $actor = isset($e['actor_id']) ? ['type' => 'user', 'id' => (int)$e['actor_id']] : null;
            $this->audit->append($actor, (string)$e['action'], null, is_array($e['metadata'] ?? null) ? $e['metadata'] : [], isset($e['request_id']) && is_string($e['request_id']) ? $e['request_id'] : null);
        };
        $result = (new LoginService($this->pdo, $audit))->attempt((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? '', $this->requestId, session_id());
        if (!$result['ok']) $this->login('Email o contraseña inválidos.');
        $this->redirect('/admin/');
    }

    private function dashboard(): never
    {
        $user = $this->currentUser();
        if ($user === null) $this->redirect('/admin/login');
        try { NotificationTransportFactory::service($this->pdo, (int)$user['business_id'])->dispatchPendingLazy((int)$user['business_id']); } catch (Throwable) { /* notification sweep is best-effort (spec N3) */ }
        $metrics = (new ReportsRepository(new PdoConnection('', factory: fn () => $this->pdo)))->dashboard((int)$user['id']);
        echo $this->tpl->render('dashboard', ['csrf' => $this->csrf(), 'user' => $user, 'metrics' => $metrics]);
        exit;
    }

    private function logout(): never
    {
        $user = $this->currentUser();
        if ($user === null) $this->redirect('/admin/login');
        if (!$this->validCsrf($_POST['csrf'] ?? null)) $this->error('La solicitud venció. Volvé a intentar.', 419);
        $this->sessions->logout($_SESSION['auth_sid'] ?? null);
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        $this->audit->append(['type' => 'user', 'id' => (int)$user['id']], 'auth.logout', null, [], $this->requestId);
        $this->redirect('/admin/login');
    }

    private function currentUser(): ?array
    {
        $sid = $_SESSION['auth_sid'] ?? null;
        if (!is_string($sid) || $this->sessions->validate($sid) === null) return null;
        $stmt = $this->pdo->prepare('SELECT u.id, u.business_id, u.name, u.email, b.name business_name FROM users u INNER JOIN businesses b ON b.id = u.business_id WHERE u.id = (SELECT user_id FROM auth_sessions WHERE sid_hash = ? LIMIT 1) LIMIT 1');
        $stmt->execute([AuthSession::hash($sid)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function pdo(): PDO
    {
        $cfg = $this->config();
        return new PDO((string)$cfg['dsn'], (string)($cfg['user'] ?? ''), (string)($cfg['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    }

    private function config(): array
    {
        $file = getenv('VO_INSTALLED_CONFIG_PATH') ?: $this->root . '/api/config/installed.php';
        $data = is_file($file) ? require $file : [];
        $db = is_array($data) ? ($data['database'] ?? []) : [];
        if (!is_array($db) || empty($db['dsn'])) { http_response_code(503); echo 'Configuración no disponible.'; exit; }
        return $db;
    }

    private function redirect(string $to): never { header('Location: ' . $to, true, 302); exit; }
    private function error(string $message, int $status): never { http_response_code($status); echo $this->tpl->render('login', ['csrf' => $this->csrf->issue(), 'error' => $message]); exit; }
    private function csrf(): string { $token = (isset($_COOKIE['vo_csrf']) && preg_match('/^[a-f0-9]{64}$/', (string)$_COOKIE['vo_csrf']) === 1) ? (string)$_COOKIE['vo_csrf'] : $this->csrf->issue(); $_SESSION['_csrf'] = $token; if (!headers_sent()) setcookie('vo_csrf', $token, ['path' => '/admin', 'httponly' => true, 'samesite' => 'Lax']); return $token; }
    private function validCsrf(mixed $token): bool { return $this->csrf->validate(is_string($token) ? $token : null) || (is_string($token) && isset($_COOKIE['vo_csrf']) && hash_equals((string)$_COOKIE['vo_csrf'], $token)); }
    private function isCatalogPath(string $path): bool { return $path === '/admin/catalogo' || str_starts_with($path, '/admin/categorias') || str_starts_with($path, '/admin/productos') || preg_match('#^/admin/producto/\d+(/archivar)?$#', $path) === 1 || preg_match('#^/admin/sucursales/\d+/catalogo$#', $path) === 1; }
    private function isPromotionsPath(string $path): bool { return str_starts_with($path, '/admin/promociones') || str_starts_with($path, '/admin/cupones'); }
    private function isOrdersPath(string $path): bool { return $path === '/admin/pedidos' || preg_match('#^/admin/pedidos/\d+$#', $path) === 1; }
    private function isOperationsPath(string $path): bool { return $path === '/admin/operacion' || preg_match('#^/admin/operacion/\d+/(aceptar|rechazar|preparar|listo|completar|cancelar|asignar|reasignar|retirar|entregar|fallar)$#', $path) === 1; }
    private function isDeliveryPath(string $path): bool { return $path === '/admin/delivery' || str_starts_with($path, '/admin/delivery/'); }
    private function isPaymentsPath(string $path): bool { return $path === '/admin/pagos' || preg_match('#^/admin/pagos/\d+(/(comprobante|verificar|rechazar))?$#',$path)===1; }
    private function isReportsPath(string $path): bool { return $path === '/admin/reportes' || $path === '/admin/reportes/csv'; }
}
