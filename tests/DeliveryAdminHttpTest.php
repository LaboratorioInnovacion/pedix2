<?php declare(strict_types=1);
namespace Tests;

use PDO; use RuntimeException; use Throwable; use VO\Database\MigrationRunner; use VO\Database\PdoConnection; use VO\Delivery\DeliveryRepository; use VO\Delivery\Pin; use VO\Installer\InstallerSeeder;

/**
 * Unit C HTTP coverage (specs delivery D1, D4, D9): /admin/delivery zones + persons CRUD
 * guarded by deliveries.manage, CSRF, Spanish validation, zone-in-use deactivation, and the
 * public token page delivery section (state badge, courier, PIN only while assigned/picked_up).
 */
final class DeliveryAdminHttpTest extends TestCase
{
    public function testZonesCrudHappyPathValidationAndBranchScope(): void
    {
        $s = DeliveryAdminScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $this->login($h);
            $dashboard = $h->request('GET', '/admin/');
            $this->assertTrue(str_contains($dashboard['body'], '/admin/delivery'), 'dashboard must link the Repartos module');
            $page = $h->request('GET', '/admin/delivery');
            $this->assertSame(200, $page['status']);
            foreach (['Zonas de envío', 'Repartidores', 'Centro'] as $n) $this->assertTrue(str_contains($page['body'], $n), $n);
            $csrf = $this->csrf($page['body']);

            $created = $h->request('POST', '/admin/delivery/zonas', ['csrf' => $csrf, 'branch_id' => '1', 'name' => 'Palermo', 'match_terms' => 'palermo, colegiales', 'customer_rate_cents' => '300', 'driver_payout_cents' => '150']);
            if ($created['status'] !== 302) echo "\nDEBUG CREATE STATUS: {$created['status']} BODY: " . substr(strip_tags($created['body']), 0, 300) . "\n";
            $this->assertSame(302, $created['status']);
            $this->assertTrue(str_contains($this->location($created), 'ok=zone_created'));
            $zone = $s->zoneByName('Palermo');
            $this->assertTrue((bool)$zone, 'zone must persist');
            $this->assertSame(300, (int)$zone['customer_rate_cents']); $this->assertSame(150, (int)$zone['driver_payout_cents']);
            $this->assertSame(1, (int)$zone['is_active']); $this->assertSame(1, (int)$zone['branch_id']);
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='delivery_zones.created' AND request_id IS NOT NULL")->fetchColumn());
            $listed = $h->request('GET', '/admin/delivery?ok=zone_created');
            foreach (['Palermo', 'palermo, colegiales', 'Activa', 'Zona de envío creada.'] as $n) $this->assertTrue(str_contains($listed['body'], $n), $n);

            $edited = $h->request('POST', '/admin/delivery/zonas/' . (int)$zone['id'], ['csrf' => $csrf, 'name' => 'Palermo', 'match_terms' => 'palermo, colegiales', 'customer_rate_cents' => '500', 'driver_payout_cents' => '999']);
            $this->assertSame(302, $edited['status']);
            $zone = $s->zoneByName('Palermo');
            $this->assertSame(500, (int)$zone['customer_rate_cents']); $this->assertSame(999, (int)$zone['driver_payout_cents']);

            $this->assertSame(302, $h->request('POST', '/admin/delivery/zonas/' . (int)$zone['id'] . '/estado', ['csrf' => $csrf])['status']);
            $this->assertSame(0, (int)$s->zoneByName('Palermo')['is_active']);
            $this->assertSame(302, $h->request('POST', '/admin/delivery/zonas/' . (int)$zone['id'] . '/estado', ['csrf' => $csrf])['status']);
            $this->assertSame(1, (int)$s->zoneByName('Palermo')['is_active']);

            $deleted = $h->request('POST', '/admin/delivery/zonas/' . (int)$zone['id'] . '/eliminar', ['csrf' => $csrf]);
            $this->assertSame(302, $deleted['status']);
            $this->assertTrue(str_contains($this->location($deleted), 'ok=zone_deleted'));
            $this->assertTrue($s->zoneByName('Palermo') === null, 'unreferenced zone must be deleted');

            foreach ([['name' => '', 'match_terms' => 'x', 'customer_rate_cents' => '1', 'driver_payout_cents' => '1'],
                      ['name' => 'X', 'match_terms' => '', 'customer_rate_cents' => '1', 'driver_payout_cents' => '1'],
                      ['name' => 'X', 'match_terms' => 'x', 'customer_rate_cents' => '-5', 'driver_payout_cents' => '1'],
                      ['name' => 'X', 'match_terms' => 'x', 'customer_rate_cents' => '1.5', 'driver_payout_cents' => '1']] as $bad) {
                $r = $h->request('POST', '/admin/delivery/zonas', ['csrf' => $csrf, 'branch_id' => '1', ...$bad]);
                $this->assertSame(422, $r['status']);
                $this->assertTrue(str_contains($r['body'], 'Datos de zona inválidos'), 'Spanish validation message expected');
            }
            $norte = $s->addBranch('Norte');
            $foreign = $h->request('POST', '/admin/delivery/zonas', ['csrf' => $csrf, 'branch_id' => (string)$norte, 'name' => 'Fuera', 'match_terms' => 'norte', 'customer_rate_cents' => '100', 'driver_payout_cents' => '50']);
            $this->assertSame(422, $foreign['status']);
            $this->assertTrue(str_contains($foreign['body'], 'No podés administrar zonas de esa sucursal'), 'out-of-scope branch must be typed');
            $this->assertSame(0, (int)$s->pdo->query("SELECT COUNT(*) FROM delivery_zones WHERE name='Fuera'")->fetchColumn());
        } finally { $h->stop(); $s->drop(); }
    }

    public function testPersonsCrudBranchCheckboxesAndToggle(): void
    {
        $s = DeliveryAdminScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $this->login($h);
            $csrf = $this->csrf($h->request('GET', '/admin/delivery')['body']);
            $created = $h->request('POST', '/admin/delivery/repartidores', ['csrf' => $csrf, 'name' => 'Carlos', 'phone' => '555-1234', 'branches' => ['1']]);
            $this->assertSame(302, $created['status']);
            $this->assertTrue(str_contains($this->location($created), 'ok=person_created'));
            $person = $s->personByName('Carlos');
            $this->assertTrue((bool)$person, 'person must persist');
            $this->assertSame('555-1234', (string)$person['phone']);
            $this->assertSame([1], $s->personBranches((int)$person['id']));
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='delivery_persons.created' AND request_id IS NOT NULL")->fetchColumn());
            $this->assertTrue(str_contains($h->request('GET', '/admin/delivery?ok=person_created')['body'], 'Repartidor creado.'));

            $norte = $s->addBranch('Norte'); $s->linkUserBranch(1, $norte);
            $edited = $h->request('POST', '/admin/delivery/repartidores/' . (int)$person['id'], ['csrf' => $csrf, 'name' => 'Carlos G', 'phone' => '555-9999', 'branches' => ['1', (string)$norte]]);
            $this->assertSame(302, $edited['status']);
            $person = $s->personByName('Carlos G');
            $this->assertTrue((bool)$person, 'renamed person must persist');
            $this->assertSame('555-9999', (string)$person['phone']);
            $this->assertSame([1, $norte], $s->personBranches((int)$person['id']), 'branch checkboxes must persist');

            $this->assertSame(302, $h->request('POST', '/admin/delivery/repartidores/' . (int)$person['id'] . '/estado', ['csrf' => $csrf])['status']);
            $this->assertSame(0, (int)$s->personByName('Carlos G')['is_active']);
            $this->assertTrue(str_contains($h->request('GET', '/admin/delivery?ok=person_toggled')['body'], 'Inactivo'));

            $noBranch = $h->request('POST', '/admin/delivery/repartidores', ['csrf' => $csrf, 'name' => 'Sin sucursal', 'phone' => '', 'branches' => []]);
            $this->assertSame(422, $noBranch['status']);
            $this->assertTrue(str_contains($noBranch['body'], 'al menos una sucursal'), 'Spanish person validation expected');
            $missing = $h->request('POST', '/admin/delivery/repartidores/999999/estado', ['csrf' => $csrf]);
            $this->assertSame(404, $missing['status']);
        } finally { $h->stop(); $s->drop(); }
    }

    public function testGuardDenyWithoutPermissionAndCsrfReject(): void
    {
        $s = DeliveryAdminScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $this->login($h);
            $csrf = $this->csrf($h->request('GET', '/admin/delivery')['body']);
            $s->removePermission('deliveries.manage');
            $deny = $h->request('GET', '/admin/delivery');
            $this->assertSame(403, $deny['status']);
            $this->assertTrue(str_contains($deny['body'], 'No tenés permiso para administrar repartos'), 'Spanish 403 message expected');
            $row = $s->pdo->query("SELECT actor_id, request_id, metadata_json FROM audit_log WHERE action='authz.denied' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $this->assertTrue((bool)$row, 'authz.denied audit missing');
            $this->assertSame(1, (int)$row['actor_id']);
            $this->assertNotSame('', (string)$row['request_id']);
            $this->assertTrue(str_contains((string)$row['metadata_json'], 'deliveries.manage'), 'denied permission must be recorded');
            $denyPost = $h->request('POST', '/admin/delivery/zonas', ['csrf' => $csrf, 'branch_id' => '1', 'name' => 'X', 'match_terms' => 'x', 'customer_rate_cents' => '1', 'driver_payout_cents' => '1']);
            $this->assertSame(403, $denyPost['status']);
            $this->assertSame(0, (int)$s->pdo->query('SELECT COUNT(*) FROM delivery_zones')->fetchColumn());

            $s->grantPermission('deliveries.manage');
            $noCsrf = $h->request('POST', '/admin/delivery/zonas', ['branch_id' => '1', 'name' => 'X', 'match_terms' => 'x', 'customer_rate_cents' => '1', 'driver_payout_cents' => '1']);
            $this->assertSame(419, $noCsrf['status']);
            $badCsrf = $h->request('POST', '/admin/delivery/zonas', ['csrf' => 'deadbeef', 'branch_id' => '1', 'name' => 'X', 'match_terms' => 'x', 'customer_rate_cents' => '1', 'driver_payout_cents' => '1']);
            $this->assertSame(419, $badCsrf['status']);
            $this->assertSame(0, (int)$s->pdo->query('SELECT COUNT(*) FROM delivery_zones')->fetchColumn(), 'CSRF reject must leave data untouched');
        } finally { $h->stop(); $s->drop(); }
    }

    public function testZoneReferencedByCartDeactivatesInsteadOfDelete(): void
    {
        $s = DeliveryAdminScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $zoneId = $s->seedZone(1, 'Zona A', 'a, b', 300, 150, 1);
            $s->seedCartReferencingZone($zoneId);
            $this->login($h);
            $csrf = $this->csrf($h->request('GET', '/admin/delivery')['body']);
            $attempt = $h->request('POST', '/admin/delivery/zonas/' . $zoneId . '/eliminar', ['csrf' => $csrf]);
            $this->assertSame(302, $attempt['status']);
            $this->assertTrue(str_contains($this->location($attempt), 'err=zone_in_use'));
            $zone = $s->zoneById($zoneId);
            $this->assertTrue((bool)$zone, 'referenced zone must survive');
            $this->assertSame(0, (int)$zone['is_active'], 'referenced zone must be deactivated instead');
            $this->assertSame(0, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='delivery_zones.deleted'")->fetchColumn());
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='delivery_zones.toggled' AND metadata_json LIKE '%in_use%' AND request_id IS NOT NULL")->fetchColumn());
            $flash = $h->request('GET', '/admin/delivery?err=zone_in_use');
            $this->assertTrue(str_contains($flash['body'], 'se desactivó en lugar de eliminarse'), 'Spanish deactivation flash expected');
            $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM carts WHERE delivery_zone_id=' . $zoneId)->fetchColumn(), 'cart reference must stay intact');
        } finally { $h->stop(); $s->drop(); }
    }

    public function testPublicPageShowsPinWhileAssignedAndHidesAfterDelivered(): void
    {
        $s = DeliveryAdminScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $s->ensurePinKey(); // fresh installs provision lazily; do it up front so the recomputed PIN matches from the first request
            $personId = $s->seedPerson('Carlos', '555-1234', [1]);
            $order = $s->seedDeliveryOrder('B-900001', 'accepted', 'assigned', $personId);
            $expected = Pin::code($s->pinKey(), $order['delivery'], $order['order']);
            $this->assertTrue(preg_match('/^\d{6}$/', $expected) === 1, 'PIN must be six digits');

            $assigned = $h->request('GET', '/pedido/' . $order['token']);
            $this->assertSame(200, $assigned['status']);
            foreach (['Envío a domicilio', 'Asignado', 'Carlos'] as $n) $this->assertTrue(str_contains($assigned['body'], $n), $n);
            $this->assertTrue(str_contains($assigned['body'], $expected), 'assigned page must show the recomputed PIN');

            $blob = (string)$s->pdo->query('SELECT GROUP_CONCAT(metadata_json) FROM audit_log')->fetchColumn();
            $this->assertTrue(!str_contains($blob, $expected), 'PIN value must never be audited or logged');

            $s->setDeliveryState($order['delivery'], 'pending');
            $pending = $h->request('GET', '/pedido/' . $order['token']);
            $this->assertSame(200, $pending['status']);
            $this->assertTrue(str_contains($pending['body'], 'Pendiente'));
            $this->assertTrue(!str_contains($pending['body'], $expected), 'pending deliveries must not show a PIN');

            $s->setDeliveryState($order['delivery'], 'picked_up');
            $enCamino = $h->request('GET', '/pedido/' . $order['token']);
            $this->assertTrue(str_contains($enCamino['body'], 'En camino'));
            $this->assertTrue(str_contains($enCamino['body'], $expected), 'picked_up keeps the PIN visible');

            $s->setDeliveryState($order['delivery'], 'delivered');
            $delivered = $h->request('GET', '/pedido/' . $order['token']);
            $this->assertTrue(str_contains($delivered['body'], 'Entregado'));
            $this->assertTrue(!str_contains($delivered['body'], $expected), 'delivered deliveries must hide the PIN');

            $s->setDeliveryState($order['delivery'], 'cancelled');
            $cancelled = $h->request('GET', '/pedido/' . $order['token']);
            $this->assertTrue(str_contains($cancelled['body'], 'Cancelado'));
            $this->assertTrue(!str_contains($cancelled['body'], $expected), 'cancelled deliveries must hide the PIN');
        } finally { $h->stop(); $s->drop(); }
    }

    public function testPublicPageOmitsDeliverySectionForPickupOrders(): void
    {
        $s = DeliveryAdminScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $token = $s->seedPickupOrder('B-900002');
            $page = $h->request('GET', '/pedido/' . $token);
            $this->assertSame(200, $page['status']);
            $this->assertTrue(!str_contains($page['body'], 'Envío a domicilio'), 'pickup orders must not render the delivery section');
            $this->assertTrue(!str_contains($page['body'], 'Código de entrega'), 'no PIN block for pickup orders');
            $this->assertTrue(str_contains($page['body'], 'Detalle de precios'), 'page must still render normally');
        } finally { $h->stop(); $s->drop(); }
    }

    // ---- helpers ----

    private function harness(DeliveryAdminScratchDatabase $s): array
    {
        $dir = $this->tempDir('delivery_admin'); $lock = $dir . '/installed.php';
        $s->install($lock);
        $h = new \InstallerTestServer(dirname(__DIR__) . '/public_html', ['VO_INSTALLED_CONFIG_PATH' => $lock, 'VO_STORAGE_PATH' => $dir . '/storage', 'VO_HTTP_NO_REDIRECTS' => '1']);
        $h->start();
        return [$h, $lock];
    }

    private function login(\InstallerTestServer $h): void
    {
        $r = $h->request('GET', '/admin/login');
        if (!preg_match('/name="csrf" value="([^"]+)"/', $r['body'], $m)) throw new RuntimeException('Missing CSRF.');
        $this->assertSame(302, $h->request('POST', '/admin/login', ['csrf' => $m[1], 'email' => 'owner@example.test', 'password' => 'Password123'])['status']);
    }

    private function csrf(string $html): string
    {
        if (!preg_match('/name="csrf" value="([^"]+)"/', $html, $m)) throw new RuntimeException('Missing CSRF on delivery page.');
        return $m[1];
    }

    private function location(array $response): string
    {
        foreach ($response['headers'] as $header) if (stripos($header, 'Location:') === 0) return trim(substr($header, 9));
        throw new RuntimeException('Missing Location header.');
    }
}

final class DeliveryAdminScratchDatabase
{
    private function __construct(public string $name, public PDO $pdo, private PDO $server) {}

    public static function create(): self
    {
        try { $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
        catch (Throwable $e) { throw new RuntimeException('MariaDB is REQUIRED for DeliveryAdminHttpTest.', 0, $e); }
        $name = 'vo_del10_test_' . bin2hex(random_bytes(5));
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

    public function addBranch(string $name): int { $this->pdo->exec("INSERT INTO branches (business_id,name,is_active) VALUES (1,'$name',1)"); return (int)$this->pdo->lastInsertId(); }
    public function linkUserBranch(int $userId, int $branchId): void { $this->pdo->exec("INSERT IGNORE INTO user_branches (user_id,branch_id) VALUES ($userId,$branchId)"); }
    public function removePermission(string $key): void { $this->pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.permission_key=" . $this->pdo->quote($key)); }
    public function grantPermission(string $key): void { $this->pdo->exec("INSERT IGNORE INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON p.permission_key=" . $this->pdo->quote($key) . " WHERE r.name='owner'"); }

    public function seedZone(int $branchId, string $name, string $terms, int $rate, int $payout, int $active): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO delivery_zones (branch_id,name,match_terms,customer_rate_cents,driver_payout_cents,is_active) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$branchId, $name, $terms, $rate, $payout, $active]);
        return (int)$this->pdo->lastInsertId();
    }

    public function seedPerson(string $name, string $phone, array $branchIds): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO delivery_persons (business_id,name,phone,is_active) VALUES (1,?,?,1)');
        $stmt->execute([$name, $phone]);
        $id = (int)$this->pdo->lastInsertId();
        foreach ($branchIds as $branchId) { $ins = $this->pdo->prepare('INSERT IGNORE INTO delivery_person_branches (person_id,branch_id) VALUES (?,?)'); $ins->execute([$id, $branchId]); }
        return $id;
    }

    /** Delivery order + one delivery row; assigned/picked_up rows get a realistic hash of the recomputed PIN. */
    public function seedDeliveryOrder(string $number, string $status, string $state, ?int $personId): array
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare("INSERT INTO orders (business_id,branch_id,number,status,public_token,fulfillment,payment_method,customer_name,customer_email,customer_phone,gross_items_cents,item_promotions_cents,order_promotions_cents,coupon_discount_cents,payment_discount_cents,merchandise_total_cents,delivery_fee_cents,delivery_zone_name,delivery_payout_cents,grand_total_cents) VALUES (1,1,?,?,'$token','delivery','cash','Ada','ada@test','555',1500,0,0,0,0,1500,300,'Zona A',150,1800)");
        $stmt->execute([$number, $status]);
        $orderId = (int)$this->pdo->lastInsertId();
        $person = $personId !== null ? (string)$personId : 'NULL';
        $assignedAt = in_array($state, ['assigned', 'picked_up', 'delivered'], true) ? 'NOW()' : 'NULL';
        $this->pdo->exec("INSERT INTO deliveries (order_id,state,delivery_person_id,assigned_at,pin_attempts) VALUES ($orderId," . $this->pdo->quote($state) . ",$person,$assignedAt,0)");
        $deliveryId = (int)$this->pdo->lastInsertId();
        if (in_array($state, ['assigned', 'picked_up'], true)) {
            $code = Pin::code($this->pinKey(), $deliveryId, $orderId);
            $this->pdo->exec('UPDATE deliveries SET pin_hash=' . $this->pdo->quote(hash('sha256', $code)) . ' WHERE id=' . $deliveryId);
        }
        return ['order' => $orderId, 'delivery' => $deliveryId, 'token' => $token];
    }

    public function seedPickupOrder(string $number): string
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare("INSERT INTO orders (business_id,branch_id,number,status,public_token,fulfillment,payment_method,customer_name,customer_email,customer_phone,gross_items_cents,item_promotions_cents,order_promotions_cents,coupon_discount_cents,payment_discount_cents,merchandise_total_cents,delivery_fee_cents,grand_total_cents) VALUES (1,1,?,'pending',?,'pickup','cash','Ada','ada@test','555',1500,0,0,0,0,1500,0,1500)");
        $stmt->execute([$number, $token]);
        return $token;
    }

    public function seedCartReferencingZone(int $zoneId): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO carts (token_hash,branch_id,fulfillment,delivery_zone_id,delivery_fee_cents,delivery_payout_cents) VALUES (?,1,'delivery',?,300,150)");
        $stmt->execute([hash('sha256', bin2hex(random_bytes(32))), $zoneId]);
    }

    public function pinKey(): string { return (string)$this->pdo->query("SELECT setting_value FROM business_settings WHERE business_id=1 AND setting_key='delivery.pin_key'")->fetchColumn(); }
    public function ensurePinKey(): void { (new DeliveryRepository(new PdoConnection("mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4", 'root', '')))->ensurePinKey(1); }
    public function setDeliveryState(int $deliveryId, string $state): void { $this->pdo->exec('UPDATE deliveries SET state=' . $this->pdo->quote($state) . ' WHERE id=' . $deliveryId); }
    public function zoneByName(string $name): ?array { return $this->one('SELECT * FROM delivery_zones WHERE name=?', [$name]); }
    public function zoneById(int $id): ?array { return $this->one('SELECT * FROM delivery_zones WHERE id=?', [$id]); }
    public function personByName(string $name): ?array { return $this->one('SELECT * FROM delivery_persons WHERE name=?', [$name]); }
    /** @return int[] */
    public function personBranches(int $personId): array { return array_map('intval', $this->pdo->query("SELECT branch_id FROM delivery_person_branches WHERE person_id=$personId ORDER BY branch_id")->fetchAll(PDO::FETCH_COLUMN)); }
    private function one(string $sql, array $params): ?array { $stmt = $this->pdo->prepare($sql); $stmt->execute($params); return $stmt->fetch(PDO::FETCH_ASSOC) ?: null; }
    public function drop(): void { $this->server->exec("DROP DATABASE IF EXISTS `$this->name`"); }
}
