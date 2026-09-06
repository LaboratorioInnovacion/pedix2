<?php declare(strict_types=1);
namespace Tests;

use PDO; use RuntimeException; use Throwable; use VO\Database\MigrationRunner; use VO\Database\PdoConnection; use VO\Installer\InstallerSeeder;

final class AdminOperationsHttpTest extends TestCase
{
    public function testBoardSectionsButtonsBranchFilterAndAutoRefresh(): void
    {
        $s = AdminOperationsScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $ids = $this->seedFiveStatuses($s);
            $norte = $s->addBranch('Norte'); $other = $s->seedOrder('B-000006', 'pending', $norte); $s->linkUserBranch(1, $norte);
            $this->login($h);
            $board = $h->request('GET', '/admin/operacion');
            $this->assertSame(200, $board['status']);
            foreach (['Operación', 'Pendiente', 'Cambio propuesto', 'Aceptado', 'En preparación', 'Listo', 'B-000001', 'B-000002', 'B-000003', 'B-000004', 'B-000005', 'B-000006', 'Aceptar', 'Rechazar', 'Marcar listo', 'Completar', 'Cancelar', 'Motivo', 'Centro', 'Norte', '$ 15,00', 'ítems'] as $n) $this->assertTrue(str_contains($board['body'], $n), $n);
            $this->assertTrue(!str_contains($board['body'], 'http-equiv="refresh"'));
            $auto = $h->request('GET', '/admin/operacion?auto=1');
            $this->assertSame(200, $auto['status']);
            foreach (['http-equiv="refresh"', 'content="30"'] as $n) $this->assertTrue(str_contains($auto['body'], $n), $n);
            $filtered = $h->request('GET', '/admin/operacion?branch_id=' . $norte);
            $this->assertSame(200, $filtered['status']);
            $this->assertTrue(str_contains($filtered['body'], 'B-000006'), 'branch order must show');
            $this->assertTrue(!str_contains($filtered['body'], 'B-000001'), 'other branch order must be filtered out');
            $s->removePermission('orders.mark_ready');
            $limited = $h->request('GET', '/admin/operacion');
            $this->assertSame(200, $limited['status']);
            $this->assertTrue(!str_contains($limited['body'], 'Marcar listo'), 'button must disappear without permission');
            $this->assertTrue(str_contains($limited['body'], 'Completar'), 'other legal buttons stay');
            $this->assertSame(0, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='authz.denied'")->fetchColumn());
        } finally { $h->stop(); $s->drop(); }
    }

    public function testGuardCsrfAndTransitionHappyPath(): void
    {
        $s = AdminOperationsScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $ids = $this->seedFiveStatuses($s);
            $this->login($h);
            $token = $this->csrf($h);
            $noCsrf = $h->request('POST', '/admin/operacion/' . $ids['pending'] . '/aceptar');
            $this->assertSame(419, $noCsrf['status']);
            $this->assertSame('pending', $this->status($s, $ids['pending']));
            $this->assertSame(0, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='orders.accepted'")->fetchColumn());
            $ok = $h->request('POST', '/admin/operacion/' . $ids['pending'] . '/aceptar', ['csrf' => $token]);
            $this->assertSame(302, $ok['status']);
            $location = $this->location($ok);
            $this->assertTrue(str_contains($location, 'ok=aceptar'), 'redirect must carry flash param');
            $this->assertSame('accepted', $this->status($s, $ids['pending']));
            $row = $s->pdo->query("SELECT actor_id,request_id FROM audit_log WHERE action='orders.accepted' AND entity_id=" . $ids['pending'])->fetch(PDO::FETCH_ASSOC);
            $this->assertTrue((bool)$row, 'audit orders.accepted missing');
            $this->assertSame(1, (int)$row['actor_id']); $this->assertNotSame('', (string)$row['request_id']);
            $flash = $h->request('GET', $location);
            $this->assertSame(200, $flash['status']);
            $this->assertTrue(str_contains($flash['body'], 'Pedido aceptado.'), 'flash message must render');
            $s->removePermission('orders.view');
            $deny = $h->request('GET', '/admin/operacion');
            $this->assertSame(403, $deny['status']);
            $meta = (string)$s->pdo->query("SELECT metadata_json FROM audit_log WHERE action='authz.denied' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $this->assertTrue(str_contains($meta, 'orders.view'), 'authz.denied must record missing permission');
            $s->grantPermission('orders.view'); $s->removePermission('orders.prepare');
            $denied = $h->request('POST', '/admin/operacion/' . $ids['accepted'] . '/preparar', ['csrf' => $token]);
            $this->assertSame(403, $denied['status']);
            $this->assertSame('accepted', $this->status($s, $ids['accepted']));
            $this->assertSame(0, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='orders.in_progress'")->fetchColumn());
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='authz.denied' AND entity_id=" . $ids['accepted'])->fetchColumn());
        } finally { $h->stop(); $s->drop(); }
    }

    public function testCancelValidationStockAndPaymentSideEffects(): void
    {
        $s = AdminOperationsScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $cash = $s->seedOrder('B-000001', 'pending');
            $s->seedLine($cash, 3, 10, 3); $s->seedPayment($cash, 'pending_verification', 'transfer');
            $mp = $s->seedOrder('B-000002', 'accepted');
            $s->seedLine($mp, 2, 5, 2); $s->seedPayment($mp, 'approved', 'mercadopago');
            $this->login($h); $token = $this->csrf($h);
            $missing = $h->request('POST', '/admin/operacion/' . $cash . '/cancelar', ['csrf' => $token, 'reason' => '   ']);
            $this->assertSame(422, $missing['status']);
            $this->assertTrue(str_contains($missing['body'], 'motivo es obligatorio'), 'Spanish validation error expected');
            $this->assertSame('pending', $this->status($s, $cash));
            $this->assertSame('pending_verification', $this->paymentState($s, $cash));
            $this->assertSame(0, (int)$s->pdo->query("SELECT COUNT(*) FROM stock_movements WHERE order_id=$cash AND reason='release_cancelled'")->fetchColumn());
            $this->assertSame(3, (int)$this->stock($s, $cash)['reserved_quantity']);
            $done = $h->request('POST', '/admin/operacion/' . $cash . '/cancelar', ['csrf' => $token, 'reason' => 'cliente se arrepintió']);
            $this->assertSame(302, $done['status']);
            $this->assertTrue(str_contains($this->location($done), 'ok=cancelar'));
            $this->assertSame('cancelled', $this->status($s, $cash));
            $bi = $this->stock($s, $cash);
            $this->assertSame(0, (int)$bi['reserved_quantity']); $this->assertSame(10, (int)$bi['stock_quantity']);
            $mv = $s->pdo->query("SELECT quantity_delta FROM stock_movements WHERE order_id=$cash AND reason='release_cancelled'")->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame(1, count($mv)); $this->assertSame(-3, (int)$mv[0]['quantity_delta']);
            $this->assertSame('cancelled', $this->paymentState($s, $cash));
            $row = $s->pdo->query("SELECT metadata_json,request_id FROM audit_log WHERE action='orders.cancelled' AND entity_id=$cash")->fetch(PDO::FETCH_ASSOC);
            $this->assertTrue((bool)$row); $this->assertNotSame('', (string)$row['request_id']);
            $meta = json_decode((string)$row['metadata_json'], true);
            $this->assertSame('cliente se arrepintió', (string)$meta['reason']);
            $refund = $h->request('POST', '/admin/operacion/' . $mp . '/cancelar', ['csrf' => $token, 'reason' => 'Cliente pidió anular']);
            $this->assertSame(302, $refund['status']);
            $this->assertSame('cancelled', $this->status($s, $mp));
            $this->assertSame('approved', $this->paymentState($s, $mp));
            $metaMp = json_decode((string)$s->pdo->query("SELECT metadata_json FROM audit_log WHERE action='orders.cancelled' AND entity_id=$mp")->fetchColumn(), true);
            $this->assertTrue(($metaMp['refund_required'] ?? null) === true, 'refund_required flag missing');
        } finally { $h->stop(); $s->drop(); }
    }

    public function testDetailButtonsListStaysCleanAndSweepExpires(): void
    {
        $s = AdminOperationsScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $ids = $this->seedFiveStatuses($s);
            $this->login($h);
            $pending = $h->request('GET', '/admin/pedidos/' . $ids['pending']);
            $this->assertSame(200, $pending['status']);
            foreach (['Aceptar', 'Rechazar', 'Motivo'] as $n) $this->assertTrue(str_contains($pending['body'], $n), $n);
            foreach (['Completar', 'Marcar listo'] as $n) $this->assertTrue(!str_contains($pending['body'], $n), $n);
            $ready = $h->request('GET', '/admin/pedidos/' . $ids['ready']);
            $this->assertSame(200, $ready['status']);
            $this->assertTrue(str_contains($ready['body'], 'Completar'), 'ready detail must offer complete');
            $this->assertTrue(!str_contains($ready['body'], 'Aceptar'), 'ready detail must not offer accept');
            $list = $h->request('GET', '/admin/pedidos');
            $this->assertSame(200, $list['status']);
            $this->assertTrue(str_contains($list['body'], 'B-000001'));
            $this->assertTrue(!str_contains($list['body'], 'Aceptar') && !str_contains($list['body'], 'Rechazar'), 'orders list must stay button-free');
            $s->pdo->exec('UPDATE orders SET created_at=DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE id=' . $ids['pending']);
            $board = $h->request('GET', '/admin/operacion');
            $this->assertSame(200, $board['status']);
            $this->assertSame('expired', $this->status($s, $ids['pending']));
            $this->assertSame('ready', $this->status($s, $ids['ready']));
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='orders.expired' AND entity_id=" . $ids['pending'])->fetchColumn());
        } finally { $h->stop(); $s->drop(); }
    }

    // ---- helpers ----

    /** @return array<string,int> */
    private function seedFiveStatuses(AdminOperationsScratchDatabase $s): array
    {
        return ['pending' => $s->seedOrder('B-000001', 'pending'), 'change_proposed' => $s->seedOrder('B-000002', 'change_proposed'),
            'accepted' => $s->seedOrder('B-000003', 'accepted'), 'in_progress' => $s->seedOrder('B-000004', 'in_progress'), 'ready' => $s->seedOrder('B-000005', 'ready')];
    }

    private function harness(AdminOperationsScratchDatabase $s): array { $dir = $this->tempDir('admin_operations'); $lock = $dir . '/installed.php'; $s->install($lock); $h = new \InstallerTestServer(dirname(__DIR__) . '/public_html', ['VO_INSTALLED_CONFIG_PATH' => $lock, 'VO_STORAGE_PATH' => $dir . '/storage', 'VO_HTTP_NO_REDIRECTS' => '1']); $h->start(); return [$h, $lock]; }
    private function login(\InstallerTestServer $h): void { $r = $h->request('GET', '/admin/login'); if (!preg_match('/name="csrf" value="([^"]+)"/', $r['body'], $m)) throw new \RuntimeException('Missing CSRF.'); $this->assertSame(302, $h->request('POST', '/admin/login', ['csrf' => $m[1], 'email' => 'owner@example.test', 'password' => 'Password123'])['status']); }
    private function csrf(\InstallerTestServer $h): string { $r = $h->request('GET', '/admin/operacion'); if (!preg_match('/name="csrf" value="([^"]+)"/', $r['body'], $m)) throw new \RuntimeException('Missing CSRF on board.'); return $m[1]; }
    private function location(array $response): string { foreach ($response['headers'] as $header) if (stripos($header, 'Location:') === 0) return trim(substr($header, 9)); throw new \RuntimeException('Missing Location header.'); }
    private function status(AdminOperationsScratchDatabase $s, int $orderId): string { return (string)$s->pdo->query('SELECT status FROM orders WHERE id=' . $orderId)->fetchColumn(); }
    private function paymentState(AdminOperationsScratchDatabase $s, int $orderId): string { return (string)$s->pdo->query('SELECT state FROM payments WHERE order_id=' . $orderId)->fetchColumn(); }
    private function stock(AdminOperationsScratchDatabase $s, int $orderId): array { $branch = (int)$s->pdo->query('SELECT branch_id FROM orders WHERE id=' . $orderId)->fetchColumn(); $item = (int)$s->pdo->query('SELECT item_id FROM order_items WHERE order_id=' . $orderId . ' LIMIT 1')->fetchColumn(); return $s->pdo->query('SELECT stock_quantity,reserved_quantity FROM branch_items WHERE branch_id=' . $branch . ' AND item_id=' . $item)->fetch(PDO::FETCH_ASSOC); }
}

final class AdminOperationsScratchDatabase
{
    private function __construct(public string $name, public PDO $pdo, private PDO $server) {}
    public static function create(): self
    {
        try { $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
        catch (Throwable $e) { throw new \RuntimeException('MariaDB is REQUIRED for AdminOperationsHttpTest.', 0, $e); }
        $name = 'vo_opb_test_' . bin2hex(random_bytes(5));
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
    public function seedLine(int $orderId, int $qty, int $stock, int $reserved): void
    {
        $this->pdo->exec("INSERT IGNORE INTO categories (id,name,slug) VALUES (1,'Food','food')");
        $slug = 'burger-' . bin2hex(random_bytes(4));
        $this->pdo->exec("INSERT INTO catalog_items (category_id,type,name,slug,base_price_cents,requires_variant,allows_delivery,is_active) VALUES (1,'product','Burger','$slug',1500,0,1,1)");
        $item = (int)$this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO item_variants (item_id,name,price_cents,is_active) VALUES ($item,'Default',1500,1)");
        $variant = (int)$this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO branch_items (branch_id,item_id,is_available,stock_mode,stock_quantity,reserved_quantity) VALUES (1,$item,1,'simple',$stock,$reserved)");
        $this->pdo->exec("INSERT INTO branch_variants (branch_id,variant_id,is_available) VALUES (1,$variant,1)");
        $this->pdo->exec("INSERT INTO order_items (order_id,branch_id,item_id,variant_id,item_name,variant_name,quantity,unit_price_cents,modifiers_total_cents,gross_unit_cents,gross_line_cents,line_total_cents) VALUES ($orderId,1,$item,$variant,'Burger','Default',$qty,1500,0,1500,1500,1500)");
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
