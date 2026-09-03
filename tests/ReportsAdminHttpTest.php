<?php declare(strict_types=1);
namespace Tests;

use PDO; use RuntimeException; use Throwable; use VO\Database\MigrationRunner; use VO\Database\PdoConnection; use VO\Delivery\DeliveryRepository; use VO\Installer\InstallerSeeder;

/**
 * Unit B (vo-reports): /admin/reportes page + CSV export over real HTTP (reports
 * specs R1–R8, admin-shell delta). Guard deny is audited (403 + authz.denied
 * naming reports.view), anonymous redirects to login, the owner sees hand-computed
 * metric cents for a seeded 3-day scenario, filters apply via query string, the CSV
 * streams BOM + semicolon header + daily rows + TOTAL with the ventas_<from>_<to>
 * filename, a branch-scoped operator sees only her branch, and the dashboard renders
 * the eleven-metric block plus the Reportes card. Serial (built-in server + MariaDB).
 */
final class ReportsAdminHttpTest extends TestCase
{
    private const RANGE = 'preset=custom&from=2026-08-10&to=2026-08-12';

    public function testReportPageMetricsDailyRowsFiltersAndCsvLink(): void
    {
        $s = ReportsAdminScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $s->seedScenario();
            $this->login($h);
            $page = $h->request('GET', '/admin/reportes?' . self::RANGE);
            $this->assertSame(200, $page['status']);
            foreach (['Reportes de ventas', 'Período', 'Sucursal', 'Medio de pago', 'Modalidad', 'Estado', 'Pedidos', 'Venta bruta', 'Descuentos', 'Delivery cobrado', 'Remuneración delivery', 'Venta neta operativa', 'Exportar CSV'] as $n) $this->assertTrue(str_contains($page['body'], $n), $n);
            // Hand-computed totals for the seeded scenario: 4 live orders (rejected/cancelled excluded).
            foreach (['$ 260,00', '$ 22,00', '$ 10,00', '$ 4,00', '$ 244,00'] as $n) $this->assertTrue(str_contains($page['body'], $n), 'totals must include ' . $n);
            $this->assertTrue(str_contains($page['body'], '2026-08-10') && str_contains($page['body'], '2026-08-11') && str_contains($page['body'], '2026-08-12'), 'daily rows must cover the three days');
            $this->assertTrue(str_contains($page['body'], 'TOTAL'), 'totals row must render');
            // Filter form carries the scoped branch selector (R7): the owner only sees Centro.
            $this->assertTrue(str_contains($page['body'], 'Centro') && !str_contains($page['body'], 'Norte'), 'branch selector must list only the owner\'s branches');
            // CSV link preserves the effective filters (custom range).
            $this->assertTrue(str_contains($page['body'], 'href="/admin/reportes/csv?preset=custom&amp;from=2026-08-10&amp;to=2026-08-12"'), 'CSV link must carry the current filters');

            // Filters via query string (R2/R3): estado override, medio, modalidad, producto.
            $cancelled = $h->request('GET', '/admin/reportes?' . self::RANGE . '&estado=cancelled');
            $this->assertTrue(str_contains($cancelled['body'], '$ 99,99'), 'estado=cancelled must isolate the cancelled order (9999)');
            $this->assertTrue(str_contains($cancelled['body'], '$ 102,99'), 'neta = 9999 − 0 + 500 − 200');
            $this->assertTrue(!str_contains($cancelled['body'], '$ 260,00'), 'default-set totals must disappear under the override');
            $mp = $h->request('GET', '/admin/reportes?' . self::RANGE . '&medio=mercadopago');
            $this->assertTrue(str_contains($mp['body'], '$ 30,00'), 'medio filter uses the payment_method snapshot');
            $delivery = $h->request('GET', '/admin/reportes?' . self::RANGE . '&modalidad=delivery');
            $this->assertTrue(str_contains($delivery['body'], '$ 130,00'), 'modalidad=delivery aggregates delivery orders only');
            $product = $h->request('GET', '/admin/reportes?' . self::RANGE . '&producto=1');
            $this->assertTrue(str_contains($product['body'], '$ 150,00'), 'producto filter must not fan out order money (2 lines of item 1)');
            // Invalid values are ignored: page still renders with defaults (R3).
            $ignored = $h->request('GET', '/admin/reportes?' . self::RANGE . '&estado=nope&producto=abc');
            $this->assertSame(200, $ignored['status']);
            $this->assertTrue(str_contains($ignored['body'], '$ 260,00'), 'invalid filter values are silently ignored');
            // Custom range only: today's live orders stay out of the fixed range.
            $this->assertTrue(!str_contains($ignored['body'], '$ 183,00'), 'today orders are outside the custom range');
        } finally { $h->stop(); $s->drop(); }
    }

    public function testCsvExportHeadersBomAndExactRows(): void
    {
        $s = ReportsAdminScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $s->seedScenario();
            $this->login($h);
            $csv = $h->request('GET', '/admin/reportes/csv?' . self::RANGE);
            $this->assertSame(200, $csv['status']);
            $type = $dispo = false;
            foreach ($csv['headers'] as $header) {
                if (stripos($header, 'Content-Type:') === 0) $type = stripos($header, 'text/csv; charset=utf-8') !== false;
                if (stripos($header, 'Content-Disposition:') === 0) $dispo = trim(substr($header, 20)) === 'attachment; filename="ventas_20260810-20260812.csv"';
            }
            $this->assertTrue($type, 'Content-Type must be text/csv; charset=utf-8');
            $this->assertTrue($dispo, 'Content-Disposition must name ventas_20260810-20260812.csv');
            $body = $csv['body'];
            $this->assertTrue(str_starts_with($body, "\xEF\xBB\xBF"), 'CSV must start with the UTF-8 BOM');
            $lines = explode("\n", substr($body, 3));
            $this->assertSame('Fecha;Pedidos;Venta bruta;Descuentos;Delivery cobrado;Remuneración delivery;Venta neta operativa', trim($lines[0]), 'header row must match the METRIC_LABELS contract');
            $this->assertSame('2026-08-10;2;$ 150,00;$ 11,00;$ 4,00;$ 1,50;$ 141,50', trim($lines[1]), 'day 1 row');
            $this->assertSame('2026-08-11;1;$ 30,00;$ 1,00;$ 0,00;$ 0,00;$ 29,00', trim($lines[2]), 'day 2 row');
            $this->assertSame('2026-08-12;1;$ 80,00;$ 10,00;$ 6,00;$ 2,50;$ 73,50', trim($lines[3]), 'day 3 row');
            $this->assertSame('TOTAL;4;$ 260,00;$ 22,00;$ 10,00;$ 4,00;$ 244,00', trim($lines[4]), 'TOTAL row');
            // Filtered CSV carries the same filter as the page (R6).
            $filtered = $h->request('GET', '/admin/reportes/csv?' . self::RANGE . '&estado=cancelled');
            $this->assertTrue(str_contains($filtered['body'], 'TOTAL;1;$ 99,99;$ 0,00;$ 5,00;$ 2,00;$ 102,99'), 'filtered TOTAL must match the override set');
        } finally { $h->stop(); $s->drop(); }
    }

    public function testGuardDenyAuditedAndAnonymousRedirect(): void
    {
        $s = ReportsAdminScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            // Anonymous first: both endpoints redirect to the login page (R1).
            foreach (['/admin/reportes', '/admin/reportes/csv'] as $path) {
                $r = $h->request('GET', $path);
                $this->assertSame(302, $r['status']);
                $this->assertTrue(str_contains($this->location($r), '/admin/login'), 'anonymous must redirect to /admin/login');
            }
            $this->login($h);
            $this->assertSame(200, $h->request('GET', '/admin/reportes?' . self::RANGE)['status']);
            $s->removePermission('reports.view');
            $deny = $h->request('GET', '/admin/reportes?' . self::RANGE);
            $this->assertSame(403, $deny['status']);
            $this->assertTrue(str_contains($deny['body'], 'No tenés permiso para ver los reportes'), 'Spanish 403 message expected');
            $row = $s->pdo->query("SELECT actor_id, request_id, metadata_json FROM audit_log WHERE action='authz.denied' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $this->assertTrue((bool)$row, 'authz.denied audit missing');
            $this->assertSame(1, (int)$row['actor_id']);
            $this->assertNotSame('', (string)$row['request_id']);
            $this->assertTrue(str_contains((string)$row['metadata_json'], 'reports.view'), 'denied permission must be recorded');
            $denyCsv = $h->request('GET', '/admin/reportes/csv?' . self::RANGE);
            $this->assertSame(403, $denyCsv['status']);
            $this->assertTrue(!str_contains($denyCsv['body'], 'Fecha;Pedidos'), 'denied CSV must not stream data');
            $this->assertSame(2, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='authz.denied' AND metadata_json LIKE '%reports.view%'")->fetchColumn(), 'CSV denial must be audited like the page');
        } finally { $h->stop(); $s->drop(); }
    }

    public function testScopedOperatorSeesOnlyOwnBranch(): void
    {
        $s = ReportsAdminScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $s->seedScenario();
            $s->seedScopedOperator('Nora', 'norte-op@example.test', 'Secreta123', 2);
            $this->loginAs($h, 'norte-op@example.test', 'Secreta123');
            $page = $h->request('GET', '/admin/reportes?' . self::RANGE);
            $this->assertSame(200, $page['status']);
            $this->assertTrue(str_contains($page['body'], '$ 500,00'), 'branch-2 operator sees her branch totals (50000)');
            $this->assertTrue(!str_contains($page['body'], '$ 260,00'), 'branch-1 numbers must never leak');
            $this->assertTrue(str_contains($page['body'], 'Norte') && !str_contains($page['body'], 'Centro'), 'branch selector lists only scoped branches');
            // Selecting an out-of-scope branch yields the empty state, never the other branch (R7).
            $foreign = $h->request('GET', '/admin/reportes?' . self::RANGE . '&branch_id=1');
            $this->assertTrue(str_contains($foreign['body'], 'No hay ventas en el período seleccionado.'), 'out-of-scope branch filter must yield the empty state');
            $this->assertTrue(str_contains($foreign['body'], '$ 0,00'), 'zero totals must render');
        } finally { $h->stop(); $s->drop(); }
    }

    public function testDashboardMetricsBlockAndReportesCard(): void
    {
        $s = ReportsAdminScratchDatabase::create(); [$h] = $this->harness($s);
        try {
            $s->seedScenario();
            $this->login($h);
            $dash = $h->request('GET', '/admin/');
            $this->assertSame(200, $dash['status']);
            foreach (['Ventas de hoy', 'Pedidos de hoy', 'Ticket promedio', 'Nuevos', 'Preparando', 'Listos', 'Buscando delivery', 'En camino', 'Cancelados', 'Rechazados', 'Stock bajo'] as $n) $this->assertTrue(str_contains($dash['body'], $n), 'metric label ' . $n);
            // Seeded expectations: ventas 18300 (12000+3000+1000+2300), pedidos 4, ticket 4575.
            $this->assertTrue(str_contains($dash['body'], '$ 183,00'), 'ventas de hoy must sum today\'s live orders');
            $this->assertTrue(str_contains($dash['body'], '$ 45,75'), 'ticket promedio = intdiv(18300, 4)');
            $this->assertTrue(str_contains($dash['body'], 'href="/admin/reportes">Reportes</a>'), 'Reportes card must link /admin/reportes');
            $this->assertTrue(!str_contains($dash['body'], '<h2>Reportes</h2>'), 'Reportes must not remain a pending placeholder');
            // Scope: a branch-2 operator sees zero activity for branch 1 metrics.
            $s->seedScopedOperator('Nora', 'norte-op@example.test', 'Secreta123', 2);
            $this->loginAs($h, 'norte-op@example.test', 'Secreta123');
            $scoped = $h->request('GET', '/admin/');
            $this->assertTrue(str_contains($scoped['body'], '$ 0,00'), 'scoped operator must see zero branch-1 money');
            $this->assertTrue(!str_contains($scoped['body'], '$ 183,00'), 'branch-1 ventas must not leak to branch-2 operator');
        } finally { $h->stop(); $s->drop(); }
    }

    // ---- helpers ----

    private function harness(ReportsAdminScratchDatabase $s): array
    {
        $dir = $this->tempDir('reports_admin'); $lock = $dir . '/installed.php';
        $s->install($lock);
        $h = new \InstallerTestServer(dirname(__DIR__) . '/public_html', ['VO_INSTALLED_CONFIG_PATH' => $lock, 'VO_STORAGE_PATH' => $dir . '/storage', 'VO_HTTP_NO_REDIRECTS' => '1']);
        $h->start();
        return [$h, $lock];
    }

    private function login(\InstallerTestServer $h): void { $this->loginAs($h, 'owner@example.test', 'Password123'); }

    private function loginAs(\InstallerTestServer $h, string $email, string $password): void
    {
        $r = $h->request('GET', '/admin/login');
        if (!preg_match('/name="csrf" value="([^"]+)"/', $r['body'], $m)) throw new RuntimeException('Missing CSRF.');
        $this->assertSame(302, $h->request('POST', '/admin/login', ['csrf' => $m[1], 'email' => $email, 'password' => $password])['status'], 'login must succeed for ' . $email);
    }

    private function location(array $response): string
    {
        foreach ($response['headers'] as $header) if (stripos($header, 'Location:') === 0) return trim(substr($header, 9));
        throw new RuntimeException('Missing Location header.');
    }
}

final class ReportsAdminScratchDatabase
{
    private function __construct(public string $name, public PDO $pdo, private PDO $server) {}

    public static function create(): self
    {
        try { $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
        catch (Throwable $e) { throw new RuntimeException('MariaDB is REQUIRED for ReportsAdminHttpTest.', 0, $e); }
        $name = 'vo_rep12_test_' . bin2hex(random_bytes(5));
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

    /**
     * Branch 1 (Centro, owner's) + branch 2 (Norte). Fixed-range orders: o1 completed
     * with all four discount buckets · o2 pending, 2 lines of item 1, fee 400/payout 150
     * · o3 REJECTED · o4 change_proposed (mercadopago, coupon 100) · o5 CANCELLED ·
     * o6 completed delivery, item-promo 1000, fee 600/payout 250 · o7 branch 2 (50000).
     * Today's live orders + deliveries + stock feed the dashboard assertions.
     */
    public function seedScenario(): void
    {
        $this->pdo->exec("INSERT INTO branches (business_id,name,is_active) VALUES (1,'Norte',1)");
        $this->pdo->exec("INSERT INTO categories (name,slug) VALUES ('R1','cat-r1'),('R2','cat-r2')");
        $this->pdo->exec("INSERT INTO catalog_items (category_id,type,name,slug,base_price_cents) VALUES (1,'product','Alfa','alfa-http',5000),(1,'product','Brownie','brownie-http',5000),(2,'product','Torta','torta-http',8000)");
        $this->pdo->exec("INSERT INTO branch_items (branch_id,item_id,stock_mode,stock_quantity) VALUES (1,1,'simple',0),(1,2,'simple',5),(1,3,'none',0),(2,1,'simple',0)");
        [$o1, $o2, , $o4, , $o6] = [
            $this->order(['created_at' => '2026-08-10 12:00:00', 'gross' => 10000, 'item_promo' => 500, 'order_promo' => 300, 'coupon' => 200, 'pay_disc' => 100]),
            $this->order(['status' => 'pending', 'fulfillment' => 'delivery', 'payment_method' => null, 'created_at' => '2026-08-10 18:00:00', 'gross' => 5000, 'fee' => 400, 'payout' => 150]),
            $this->order(['status' => 'rejected', 'fulfillment' => 'delivery', 'created_at' => '2026-08-11 13:00:00', 'gross' => 7000, 'fee' => 300, 'payout' => 100]),
            $this->order(['status' => 'change_proposed', 'payment_method' => 'mercadopago', 'created_at' => '2026-08-11 11:00:00', 'gross' => 3000, 'coupon' => 100]),
            $this->order(['status' => 'cancelled', 'fulfillment' => 'delivery', 'created_at' => '2026-08-12 09:00:00', 'gross' => 9999, 'fee' => 500, 'payout' => 200]),
            $this->order(['fulfillment' => 'delivery', 'created_at' => '2026-08-12 20:00:00', 'gross' => 8000, 'item_promo' => 1000, 'fee' => 600, 'payout' => 250]),
            $this->order(['branch_id' => 2, 'created_at' => '2026-08-10 15:00:00', 'gross' => 50000]),
        ];
        $this->itemLine($o1, 1, 5000); $this->itemLine($o1, 2, 5000);
        $this->itemLine($o2, 1, 2500); $this->itemLine($o2, 1, 2500);
        $this->itemLine($o4, 2, 3000);
        $this->itemLine($o6, 3, 8000);
        // Today's live orders for the dashboard: ventas 12000+3000+1000+2300, pedidos 4.
        $t4 = $this->order(['status' => 'ready', 'fulfillment' => 'delivery', 'payment_method' => 'transfer', 'created_at' => date('Y-m-d 11:00:00'), 'gross' => 2000, 'fee' => 300, 'payout' => 100]);
        $this->order(['created_at' => date('Y-m-d 10:00:00'), 'gross' => 12000]);
        $this->order(['status' => 'pending', 'created_at' => date('Y-m-d 10:30:00'), 'gross' => 3000]);
        $this->order(['status' => 'in_progress', 'created_at' => date('Y-m-d 10:45:00'), 'gross' => 1000]);
        $this->order(['status' => 'cancelled', 'created_at' => date('Y-m-d 11:15:00'), 'gross' => 500]);
        $this->order(['status' => 'rejected', 'created_at' => date('Y-m-d 11:30:00'), 'gross' => 700]);
        $dr = new DeliveryRepository(new PdoConnection("mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4", 'root', ''));
        $dr->createPendingForOrder($o2);                                          // buscando (pending)
        $dr->createPendingForOrder($t4);
        $this->pdo->exec("UPDATE deliveries SET state='assigned' WHERE order_id=$t4"); // buscando (assigned)
        $dr->createPendingForOrder($o6);
        $this->pdo->exec("UPDATE deliveries SET state='picked_up' WHERE order_id=$o6"); // en camino
    }

    /** Second operator scoped to $branchId only (owner role → full permissions). */
    public function seedScopedOperator(string $name, string $email, string $password, int $branchId): void
    {
        $q = $this->pdo->prepare("INSERT INTO users (business_id,name,email,password_hash) VALUES (1,?,?,?)");
        $q->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int)$this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO user_roles (user_id,role_id) SELECT $userId,id FROM roles WHERE name='owner'");
        $this->pdo->exec("INSERT INTO user_branches (user_id,branch_id) VALUES ($userId,$branchId)");
    }

    private function order(array $o): int
    {
        static $n = 0;
        $o += ['branch_id' => 1, 'status' => 'completed', 'fulfillment' => 'pickup', 'payment_method' => 'transfer', 'created_at' => '2026-08-10 12:00:00', 'gross' => 0, 'item_promo' => 0, 'order_promo' => 0, 'coupon' => 0, 'pay_disc' => 0, 'fee' => 0, 'payout' => 0];
        $merch = $o['gross'] - $o['item_promo'] - $o['order_promo'] - $o['coupon'] - $o['pay_disc'];
        $pm = $o['payment_method'] === null ? 'NULL' : $this->pdo->quote((string)$o['payment_method']);
        $this->pdo->exec("INSERT INTO orders (business_id,branch_id,number,status,public_token,fulfillment,payment_method,customer_name,gross_items_cents,item_promotions_cents,order_promotions_cents,coupon_discount_cents,payment_discount_cents,merchandise_total_cents,delivery_fee_cents,delivery_payout_cents,grand_total_cents,created_at) VALUES (1,'{$o['branch_id']}','HR-" . str_pad((string)++$n, 6, '0', STR_PAD_LEFT) . "','{$o['status']}','" . bin2hex(random_bytes(32)) . "','{$o['fulfillment']}',$pm,'C','{$o['gross']}','{$o['item_promo']}','{$o['order_promo']}','{$o['coupon']}','{$o['pay_disc']}','$merch','{$o['fee']}','{$o['payout']}','" . ($merch + $o['fee']) . "','{$o['created_at']}')");
        return (int)$this->pdo->lastInsertId();
    }

    private function itemLine(int $orderId, int $itemId, int $grossLine): void
    {
        $this->pdo->exec("INSERT INTO order_items (order_id,branch_id,item_id,item_name,quantity,unit_price_cents,gross_unit_cents,gross_line_cents,line_total_cents) SELECT $orderId,branch_id,$itemId,'Item',1,$grossLine,$grossLine,$grossLine,$grossLine FROM orders WHERE id=$orderId");
    }

    public function removePermission(string $key): void { $this->pdo->exec("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.permission_key=" . $this->pdo->quote($key)); }
    public function drop(): void { $this->server->exec("DROP DATABASE IF EXISTS `$this->name`"); }
}
