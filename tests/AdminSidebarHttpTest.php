<?php declare(strict_types=1);
namespace Tests;

use PDO; use RuntimeException; use Throwable; use VO\Database\MigrationRunner; use VO\Database\PdoConnection; use VO\Installer\InstallerSeeder;

/**
 * Admin shell UX: permission-aware sidebar, dashboard quick flows (aceptar/completar
 * via the SAME /admin/operacion endpoints) and the order-number jump. Follows the
 * AdminOperationsHttpTest scratch-database + in-process HTTP server patterns.
 */
final class AdminSidebarHttpTest extends TestCase
{
    public function testSidebarEntriesActiveStateAndPermissionHiding(): void
    {
        $s = AdminSidebarScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $this->login($h);
            $home = $h->request('GET', '/admin/');
            $this->assertSame(200, $home['status']);
            foreach (['Inicio', 'Catálogo', 'Pedidos', 'Operación', 'Pagos', 'Delivery', 'Notificaciones', 'Configuración', 'Reportes', 'Demo', 'Ir a pedido', 'N° de pedido', 'Cerrar sesión'] as $n) {
                $this->assertTrue(str_contains($home['body'], $n), 'sidebar must render ' . $n);
            }
            $this->assertTrue(str_contains($home['body'], 'name="q"'), 'sidebar jump form must carry q');
            $list = $h->request('GET', '/admin/pedidos');
            $this->assertSame(200, $list['status']);
            $this->assertTrue(str_contains($list['body'], 'href="/admin/pedidos" class="active"'), 'current entry must be highlighted');
            $this->assertTrue(!str_contains($list['body'], 'href="/admin/" class="active"'), 'non-current entries must not be highlighted');
            $this->assertTrue(!str_contains($list['body'], '/admin/operacion/'), 'orders list must stay button-free (no transition forms)');
            $s->removePermission('deliveries.manage');
            $s->removePermission('reports.view');
            $list = $h->request('GET', '/admin/pedidos');
            $this->assertSame(200, $list['status']);
            foreach (['>Delivery<', '>Reportes<'] as $n) $this->assertTrue(!str_contains($list['body'], $n), 'forbidden entry must be hidden: ' . $n);
            foreach (['>Inicio<', '>Operación<', '>Configuración<'] as $n) $this->assertTrue(str_contains($list['body'], $n), 'allowed entry must stay: ' . $n);
        } finally { $h->stop(); $s->drop(); }
    }

    public function testQuickAcceptCompleteAndPaymentsLink(): void
    {
        $s = AdminSidebarScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $first = $s->seedOrder('B-000001', 'pending');
            $second = $s->seedOrder('B-000002', 'pending');
            $ready = $s->seedOrder('B-000003', 'ready');
            $s->seedPayment($first, 'pending_verification', 'transfer');
            $this->login($h);
            $token = $this->csrf($h);
            $home = $h->request('GET', '/admin/');
            $this->assertSame(200, $home['status']);
            foreach (['Acciones rápidas', 'Pedidos pendientes', 'Listos para completar', 'B-000001', 'B-000002', 'B-000003', 'Pagos por verificar: 1', '/admin/pagos?state=pending_verification'] as $n) {
                $this->assertTrue(str_contains($home['body'], $n), 'dashboard quick flow must render ' . $n);
            }
            $this->assertTrue(strpos($home['body'], 'B-000001') < strpos($home['body'], 'B-000002'), 'pending rows must be oldest first');
            $ok = $h->request('POST', '/admin/operacion/' . $first . '/aceptar', ['csrf' => $token, 'back' => 'dashboard']);
            $this->assertSame(302, $ok['status']);
            $this->assertSame('/admin/?ok=aceptar', $this->location($ok));
            $this->assertSame('accepted', $this->status($s, $first));
            $row = $s->pdo->query("SELECT actor_id,request_id FROM audit_log WHERE action='orders.accepted' AND entity_id=" . $first)->fetch(PDO::FETCH_ASSOC);
            $this->assertTrue((bool)$row, 'audit orders.accepted missing');
            $this->assertSame(1, (int)$row['actor_id']); $this->assertNotSame('', (string)$row['request_id']);
            $flash = $h->request('GET', '/admin/?ok=aceptar');
            $this->assertSame(200, $flash['status']);
            $this->assertTrue(str_contains($flash['body'], 'Pedido aceptado.'), 'flash must render on dashboard');
            $this->assertTrue(!str_contains($flash['body'], 'B-000001'), 'accepted order must leave the pending quick list');
            $done = $h->request('POST', '/admin/operacion/' . $ready . '/completar', ['csrf' => $token, 'back' => 'dashboard']);
            $this->assertSame(302, $done['status']);
            $this->assertSame('/admin/?ok=completar', $this->location($done));
            $this->assertSame('completed', $this->status($s, $ready));
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='orders.completed' AND entity_id=" . $ready)->fetchColumn());
            $tail = $h->request('GET', '/admin/');
            $this->assertSame(200, $tail['status']);
            foreach (['Listos para completar', 'Completar'] as $n) $this->assertTrue(!str_contains($tail['body'], $n), 'empty quick group must be hidden: ' . $n);
            $this->assertTrue(str_contains($tail['body'], '>B-000002</a>'), 'remaining pending order must stay listed');
            $this->assertTrue(str_contains($tail['body'], 'Pagos por verificar: 1'), 'payments link must persist');
        } finally { $h->stop(); $s->drop(); }
    }

    public function testQuickJumpByNumberAndPrefixList(): void
    {
        $s = AdminSidebarScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $one = $s->seedOrder('B-000001', 'pending');
            $two = $s->seedOrder('B-000002', 'ready');
            $norte = $s->addBranch('Norte');
            $three = $s->seedOrder('B-000003', 'pending', $norte);
            $this->login($h);
            $exact = $h->request('GET', '/admin/pedidos?q=B-000001');
            $this->assertSame(302, $exact['status']);
            $this->assertSame('/admin/pedidos/' . $one, $this->location($exact));
            $this->assertSame(200, $h->request('GET', '/admin/pedidos/' . $one)['status']);
            $digits = $h->request('GET', '/admin/pedidos?q=2');
            $this->assertSame(302, $digits['status'], 'bare digits must resolve via B-NNNNNN padding');
            $this->assertSame('/admin/pedidos/' . $two, $this->location($digits));
            $prefix = $h->request('GET', '/admin/pedidos?q=B-000');
            $this->assertSame(200, $prefix['status']);
            $this->assertTrue(str_contains($prefix['body'], 'B-000001') && str_contains($prefix['body'], 'B-000002'), 'prefix list must show matching orders');
            $this->assertTrue(!str_contains($prefix['body'], 'B-000003'), 'out-of-scope orders must never appear');
            $scoped = $h->request('GET', '/admin/pedidos?q=B-000003');
            $this->assertSame(200, $scoped['status'], 'invisible order must fall through to the filtered list');
            $this->assertTrue(!str_contains($scoped['body'], '/admin/pedidos/' . $three), 'out-of-scope exact match must not leak the order');
            $this->assertTrue(str_contains($scoped['body'], 'Resultados para «B-000003»'), 'filtered list must show the query context');
            $junk = $h->request('GET', '/admin/pedidos?q=%C2%BF!');
            $this->assertSame(200, $junk['status']);
            $this->assertTrue(str_contains($junk['body'], 'B-000001'), 'invalid query must fall back to the full list');
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='orders.view_detail'")->fetchColumn());
        } finally { $h->stop(); $s->drop(); }
    }

    // ---- helpers ----

    private function harness(AdminSidebarScratchDatabase $s): array { $dir = $this->tempDir('admin_sidebar'); $lock = $dir . '/installed.php'; $s->install($lock); $h = new \InstallerTestServer(dirname(__DIR__) . '/public_html', ['VO_INSTALLED_CONFIG_PATH' => $lock, 'VO_STORAGE_PATH' => $dir . '/storage', 'VO_HTTP_NO_REDIRECTS' => '1']); $h->start(); return [$h, $lock]; }
    private function login(\InstallerTestServer $h): void { $r = $h->request('GET', '/admin/login'); if (!preg_match('/name="csrf" value="([^"]+)"/', $r['body'], $m)) throw new \RuntimeException('Missing CSRF.'); $this->assertSame(302, $h->request('POST', '/admin/login', ['csrf' => $m[1], 'email' => 'owner@example.test', 'password' => 'Password123'])['status']); }
    private function csrf(\InstallerTestServer $h): string { $r = $h->request('GET', '/admin/'); if (!preg_match('/name="csrf" value="([^"]+)"/', $r['body'], $m)) throw new \RuntimeException('Missing CSRF on dashboard.'); return $m[1]; }
    private function location(array $response): string { foreach ($response['headers'] as $header) if (stripos($header, 'Location:') === 0) return trim(substr($header, 9)); throw new \RuntimeException('Missing Location header.'); }
    private function status(AdminSidebarScratchDatabase $s, int $orderId): string { return (string)$s->pdo->query('SELECT status FROM orders WHERE id=' . $orderId)->fetchColumn(); }
}

final class AdminSidebarScratchDatabase
{
    private function __construct(public string $name, public PDO $pdo, private PDO $server) {}
    public static function create(): self
    {
        try { $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
        catch (Throwable $e) { throw new \RuntimeException('MariaDB is REQUIRED for AdminSidebarHttpTest.', 0, $e); }
        $name = 'vo_side_test_' . bin2hex(random_bytes(5));
        $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$name;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        return new self($name, $pdo, $server);
    }
    public function install(string $lock): void
    {
        $db = new PdoConnection("mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4", 'root', '');
        (new MigrationRunner($db, dirname(__DIR__) . '/api/database/migrations'))->run();
        (new InstallerSeeder($db))->seed(['business_name' => 'Demo', 'business_slug' => 'demo', 'branch_name' => 'Centro', 'timezone' => 'America/Argentina/Buenos_Aires', 'admin_name' => 'Owner', 'admin_email' => 'owner@example.test', 'admin_password' => 'Password123']);
        if (!is_dir(dirname($lock))) mkdir(dirname($lock), 0777, true);
        file_put_contents($lock, '<?php return ' . var_export(['database' => ['dsn' => "mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4", 'user' => 'root', 'password' => '']], true) . ';');
    }
    public function seedOrder(string $number, string $status = 'pending', int $branchId = 1): int
    {
        $this->pdo->exec("INSERT INTO orders (business_id,branch_id,number,status,public_token,fulfillment,payment_method,customer_name,customer_email,customer_phone,gross_items_cents,item_promotions_cents,order_promotions_cents,coupon_discount_cents,payment_discount_cents,merchandise_total_cents,delivery_fee_cents,grand_total_cents) VALUES (1,$branchId,'$number','$status','" . bin2hex(random_bytes(32)) . "','pickup','cash','Ada','ada@test','555',1500,0,0,0,0,1500,0,1500)");
        return (int)$this->pdo->lastInsertId();
    }
    public function seedPayment(int $orderId, string $state, string $method = 'transfer'): int
    {
        $this->pdo->exec("INSERT INTO payments (order_id,business_id,method,state,amount_cents,currency) VALUES ($orderId,1,'$method','$state',1500,'ARS')");
        return (int)$this->pdo->lastInsertId();
    }
    public function addBranch(string $name): int { $this->pdo->exec("INSERT INTO branches (business_id,name,is_active) VALUES (1,'$name',1)"); return (int)$this->pdo->lastInsertId(); }
    public function linkUserBranch(int $userId, int $branchId): void { $this->pdo->exec("INSERT IGNORE INTO user_branches (user_id,branch_id) VALUES ($userId,$branchId)"); }
    public function removePermission(string $key): void { $this->pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.permission_key=" . $this->pdo->quote($key)); }
    public function grantPermission(string $key): void { $this->pdo->exec("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key=" . $this->pdo->quote($key) . " WHERE r.name='owner'"); }
    public function drop(): void { $this->server->exec("DROP DATABASE IF EXISTS `$this->name`"); }
}
