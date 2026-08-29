<?php declare(strict_types=1);
namespace Tests;

use PDO;
use Throwable;
use VO\Database\MigrationRunner;
use VO\Database\PdoConnection;
use VO\Installer\InstallerSeeder;

final class AdminHttpTest extends TestCase
{
    public function testLoginDashboardAndLogoutFlow(): void
    {
        $scratch = AuthHttpScratchDatabase::create(); if ($scratch === null) return;
        $dir = $this->tempDir('admin_http'); $lock = $dir . '/installed.php'; $scratch->install($lock);
        $h = $this->harness(['VO_INSTALLED_CONFIG_PATH' => $lock, 'VO_STORAGE_PATH' => $dir . '/storage', 'VO_HTTP_NO_REDIRECTS' => '1']);
        try {
            $r = $h->request('GET', '/admin/');
            $this->assertSame(302, $r['status']); $this->assertHeaderContains($r['headers'], 'Location: /admin/login');

            $r = $h->request('GET', '/admin/login');
            $this->assertSame(200, $r['status']); $this->assertTrue(str_contains($r['body'], 'Ingresar al panel'));
            $loginCsrf = $this->csrf($r['body']);

            $r = $h->request('POST', '/admin/login', ['csrf' => $loginCsrf, 'email' => 'missing@example.test', 'password' => 'bad']);
            $this->assertSame(200, $r['status']); $this->assertTrue(str_contains($r['body'], 'Email o contraseña inválidos.'));
            $this->assertTrue(!str_contains($r['body'], 'missing@example.test'));

            $r = $h->request('POST', '/admin/login', ['csrf' => $loginCsrf, 'email' => 'owner@example.test', 'password' => 'Password123']);
            $this->assertSame(302, $r['status']); $this->assertHeaderContains($r['headers'], 'Location: /admin/');

            $r = $h->request('GET', '/admin/');
            $this->assertSame(200, $r['status']);
            $this->assertTrue(str_contains($r['body'], 'Demo Store'));
            $this->assertTrue(str_contains($r['body'], 'Dueño'));
            $this->assertTrue(str_contains($r['body'], 'Pedidos') && str_contains($r['body'], 'Catálogo') && str_contains($r['body'], 'Sucursales'));
            $logoutCsrf = $this->csrf($r['body']);

            $r = $h->request('POST', '/admin/logout', ['csrf' => $logoutCsrf]);
            $this->assertSame(302, $r['status']); $this->assertHeaderContains($r['headers'], 'Location: /admin/login');
            $this->assertSame(1, (int)$scratch->pdo->query('SELECT COUNT(*) FROM auth_sessions WHERE revoked_at IS NOT NULL')->fetchColumn());

            $r = $h->request('GET', '/admin/');
            $this->assertSame(302, $r['status']); $this->assertHeaderContains($r['headers'], 'Location: /admin/login');
        } finally { $h->stop(); $scratch->drop(); }
    }

    public function testCsrfRejectedLogoutKeepsSessionActive(): void
    {
        $scratch = AuthHttpScratchDatabase::create(); if ($scratch === null) return;
        $dir = $this->tempDir('admin_csrf'); $lock = $dir . '/installed.php'; $scratch->install($lock);
        $h = $this->harness(['VO_INSTALLED_CONFIG_PATH' => $lock, 'VO_STORAGE_PATH' => $dir . '/storage', 'VO_HTTP_NO_REDIRECTS' => '1']);
        try {
            $r = $h->request('GET', '/admin/login'); $csrf = $this->csrf($r['body']);
            $r = $h->request('POST', '/admin/login', ['csrf' => $csrf, 'email' => 'owner@example.test', 'password' => 'Password123']);
            $this->assertSame(302, $r['status']);
            $r = $h->request('POST', '/admin/logout', ['csrf' => 'bad-token']);
            $this->assertSame(419, $r['status']);
            $this->assertSame(0, (int)$scratch->pdo->query('SELECT COUNT(*) FROM auth_sessions WHERE revoked_at IS NOT NULL')->fetchColumn());
            $r = $h->request('GET', '/admin/');
            $this->assertSame(200, $r['status']); $this->assertTrue(str_contains($r['body'], 'Demo Store'));
        } finally { $h->stop(); $scratch->drop(); }
    }

    public function testCsrfRejectedLoginDoesNotAuthenticate(): void
    {
        $scratch = AuthHttpScratchDatabase::create(); if ($scratch === null) return;
        $dir = $this->tempDir('admin_login_csrf'); $lock = $dir . '/installed.php'; $scratch->install($lock);
        $h = $this->harness(['VO_INSTALLED_CONFIG_PATH' => $lock, 'VO_STORAGE_PATH' => $dir . '/storage', 'VO_HTTP_NO_REDIRECTS' => '1']);
        try {
            $r = $h->request('GET', '/admin/login'); $this->assertSame(200, $r['status']);
            $r = $h->request('POST', '/admin/login', ['email' => 'owner@example.test', 'password' => 'Password123']);
            $this->assertSame(419, $r['status']);
            $this->assertSame(0, (int)$scratch->pdo->query('SELECT COUNT(*) FROM auth_sessions')->fetchColumn());
            $r = $h->request('GET', '/admin/'); $this->assertSame(302, $r['status']);
        } finally { $h->stop(); $scratch->drop(); }
    }

    public function testAuthAuditEventsArePersistedWithRequestId(): void
    {
        $scratch = AuthHttpScratchDatabase::create(); if ($scratch === null) return;
        $dir = $this->tempDir('admin_audit'); $lock = $dir . '/installed.php'; $scratch->install($lock);
        $h = $this->harness(['VO_INSTALLED_CONFIG_PATH' => $lock, 'VO_STORAGE_PATH' => $dir . '/storage', 'VO_HTTP_NO_REDIRECTS' => '1']);
        try {
            $r = $h->request('GET', '/admin/login');
            $this->assertSame(200, $r['status']);
            $this->assertHeaderContains($r['headers'], 'X-Request-Id: ');
            $this->assertHeaderContains($r['headers'], "Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
            $this->assertHeaderContains($r['headers'], 'X-Content-Type-Options: nosniff');
            $this->assertHeaderContains($r['headers'], 'Referrer-Policy: no-referrer');
            $this->assertHeaderContains($r['headers'], 'Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=()');
            $loginCsrf = $this->csrf($r['body']);

            $r = $h->request('POST', '/admin/login', ['csrf' => $loginCsrf, 'email' => 'ghost@example.test', 'password' => 'bad-pass']);
            $this->assertSame(200, $r['status']); $this->assertTrue(str_contains($r['body'], 'Email o contraseña inválidos.'));

            $r = $h->request('POST', '/admin/login', ['csrf' => $loginCsrf, 'email' => 'owner@example.test', 'password' => 'Password123']);
            $this->assertSame(302, $r['status']); $this->assertHeaderContains($r['headers'], 'Location: /admin/');

            $r = $h->request('GET', '/admin/');
            $this->assertSame(200, $r['status']);
            $logoutCsrf = $this->csrf($r['body']);

            $r = $h->request('POST', '/admin/logout', ['csrf' => $logoutCsrf]);
            $this->assertSame(302, $r['status']); $this->assertHeaderContains($r['headers'], 'Location: /admin/login');

            foreach (['auth.login_failed', 'auth.login_success', 'auth.logout'] as $action) {
                $stmt = $scratch->pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action = ? AND request_id IS NOT NULL AND request_id <> ''");
                $stmt->execute([$action]);
                $count = (int)$stmt->fetchColumn();
                $this->assertTrue($count >= 1, "audit_log expected >=1 row for `{$action}` with non-empty request_id, got {$count}.");
            }

            $rows = $scratch->pdo->query('SELECT actor_type, actor_id, action, entity_type, entity_id, metadata_json, request_id FROM audit_log')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertTrue(count($rows) >= 1);
            foreach ($rows as $row) {
                $blob = implode('|', array_map('strval', $row));
                foreach (['ghost@example.test', 'owner@example.test', 'Password123', 'bad-pass'] as $needle) {
                    $this->assertTrue(!str_contains($blob, $needle), "audit_log plaintext leak: found `{$needle}` in `{$blob}`.");
                }
            }
        } finally { $h->stop(); $scratch->drop(); }
    }

    private function harness(array $env=[]): \InstallerTestServer { $h = new \InstallerTestServer(dirname(__DIR__) . '/public_html', $env); $h->start(); return $h; }
    private function csrf(string $html): string { if (!preg_match('/name="csrf" value="([^"]+)"/', $html, $m)) throw new \RuntimeException('Missing CSRF token. Body: ' . substr($html, 0, 120)); return $m[1]; }
    private function assertHeaderContains(array $headers, string $needle): void { $this->assertTrue((bool)array_filter($headers, static fn (string $h): bool => str_contains($h, $needle)), 'Missing header ' . $needle); }
}

final class AuthHttpScratchDatabase
{
    private function __construct(public string $name, public PDO $pdo, private PDO $server) {}
    public static function create(): ?self { try { $s = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); } catch (Throwable $e) { echo 'SKIP MariaDB unavailable: ' . $e->getMessage() . PHP_EOL; return null; } $n = 'vo_auth_test_' . bin2hex(random_bytes(5)); $s->exec("CREATE DATABASE `$n` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $p = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$n;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); return new self($n, $p, $s); }
    public function install(string $lock): void { $db = new PdoConnection("mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4", 'root', ''); (new MigrationRunner($db, dirname(__DIR__) . '/api/database/migrations'))->run(); (new InstallerSeeder($db))->seed(['business_name' => 'Demo Store', 'business_slug' => 'demo-store', 'branch_name' => 'Centro', 'timezone' => 'America/Argentina/Buenos_Aires', 'admin_name' => 'Dueño', 'admin_email' => 'owner@example.test', 'admin_password' => 'Password123']); if (!is_dir(dirname($lock))) mkdir(dirname($lock), 0777, true); file_put_contents($lock, '<?php return ' . var_export(['database' => ['dsn' => "mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4", 'user' => 'root', 'password' => '']], true) . ';'); }
    public function drop(): void { $this->server->exec("DROP DATABASE IF EXISTS `$this->name`"); }
}
