<?php declare(strict_types=1);
namespace Tests;

use PDO; use VO\Database\MigrationRunner; use VO\Delivery\DeliveryRepository; use VO\Installer\InstallerSeeder; use VO\Reports\ReportsRepository; use VO\Reports\ReportsService;

/**
 * Unit A (vo-reports): exact-cents scenarios for ReportsRepository/ReportsService on a
 * real MariaDB scratch database (vo_rep12_test_<rand>, serial).
 */
final class ReportsRepositoryTest extends TestCase
{
    private const SEED = ['business_name' => 'Rep', 'business_slug' => 'rep', 'branch_name' => 'Main', 'branch_address' => '1 St', 'branch_phone' => '555', 'timezone' => 'America/Argentina/Buenos_Aires', 'admin_name' => 'Owner', 'admin_email' => 'owner@rep.test', 'admin_password' => 'change-me-now'];
    private const FROM = '2026-08-10';
    private const TO = '2026-08-12';

    public function testDefaultSetDailyRowsTotalsAndNetaIdentity(): void
    {
        $s = $this->db();
        try {
            [$svc, , $u1] = $this->seed($s);
            $r = $svc->report($this->q($svc), $u1);
            // Daily rows (R5): one per date, ascending, same six metrics per day.
            $this->assertSame(['2026-08-10', '2026-08-11', '2026-08-12'], array_column($r['rows'], 'date'));
            $this->assertSame([2, 1, 1], array_column($r['rows'], 'orders'));
            $this->assertSame([15000, 3000, 8000], array_column($r['rows'], 'bruta'));
            $this->assertSame([1100, 100, 1000], array_column($r['rows'], 'descuentos'));
            $this->assertSame([400, 0, 600], array_column($r['rows'], 'delivery_cobrado'));
            $this->assertSame([150, 0, 250], array_column($r['rows'], 'remuneracion'));
            $this->assertSame([14150, 2900, 7350], array_column($r['rows'], 'neta'), 'neta = bruta − descuentos + cobrado − remuneración (R4)');
            $t = $r['totals'];
            $this->assertSame(4, $t['orders'], 'change_proposed counts (A1); rejected+cancelled excluded; branch 2 out of scope');
            $this->assertSame(26000, $t['bruta']);
            $this->assertSame(2200, $t['descuentos'], 'descuentos = item + order + coupon + payment buckets');
            $this->assertSame(1000, $t['delivery_cobrado']);
            $this->assertSame(400, $t['remuneracion']);
            $this->assertSame(24400, $t['neta']);
            // Arithmetic identity: neta ≡ grand_total − delivery_payout for the same set.
            $sum = $s->pdo->query("SELECT COALESCE(SUM(grand_total_cents),0), COALESCE(SUM(delivery_payout_cents),0) FROM orders WHERE status IN ('pending','change_proposed','accepted','in_progress','ready','completed') AND branch_id=1 AND created_at >= '2026-08-10' AND created_at < '2026-08-13'")->fetch(PDO::FETCH_NUM);
            $this->assertSame((int)$sum[0] - (int)$sum[1], $t['neta'], 'bruta − descuentos + delivery − payout ≡ grand − payout');
            // Daily rows sum to the totals (R5).
            $this->assertSame($t, ['orders' => array_sum(array_column($r['rows'], 'orders')), 'bruta' => array_sum(array_column($r['rows'], 'bruta')), 'descuentos' => array_sum(array_column($r['rows'], 'descuentos')), 'delivery_cobrado' => array_sum(array_column($r['rows'], 'delivery_cobrado')), 'remuneracion' => array_sum(array_column($r['rows'], 'remuneracion')), 'neta' => $t['neta']]);
        } finally { $s->drop(); }
    }

    public function testEstadoOverridesDefaultSet(): void
    {
        $s = $this->db();
        try {
            [$svc, , $u1] = $this->seed($s);
            $r = $svc->report($this->q($svc, ['estado' => 'cancelled']), $u1);
            $this->assertSame(1, $r['totals']['orders']);
            $this->assertSame(9999, $r['totals']['bruta']);
            $this->assertSame(500, $r['totals']['delivery_cobrado']);
            $this->assertSame(200, $r['totals']['remuneracion']);
            $this->assertSame(10299, $r['totals']['neta']);
            $r = $svc->report($this->q($svc, ['estado' => 'rejected']), $u1);
            $this->assertSame(1, $r['totals']['orders']);
            $this->assertSame(7000, $r['totals']['bruta']);
            $this->assertSame(7200, $r['totals']['neta']);
        } finally { $s->drop(); }
    }

    public function testProductCategoryExistsCountOrderMoneyOnce(): void
    {
        $s = $this->db();
        try {
            [$svc, , $u1] = $this->seed($s);
            // Item 1 (product A) appears on TWO lines of the pending order — money once (R3).
            $r = $svc->report($this->q($svc, ['producto' => '1']), $u1);
            $this->assertSame(2, $r['totals']['orders']);
            $this->assertSame(15000, $r['totals']['bruta'], 'EXISTS must not fan out order money per matching line');
            $this->assertSame(1100, $r['totals']['descuentos']);
            $this->assertSame(14150, $r['totals']['neta']);
            $r = $svc->report($this->q($svc, ['producto' => '2']), $u1);
            $this->assertSame(2, $r['totals']['orders'], 'product B: completed o1 line + change_proposed o4');
            $this->assertSame(13000, $r['totals']['bruta']);
            $r = $svc->report($this->q($svc, ['categoria' => '2']), $u1);
            $this->assertSame(1, $r['totals']['orders'], 'category 2 only holds item 3 (order o6)');
            $this->assertSame(8000, $r['totals']['bruta']);
            $this->assertSame(7350, $r['totals']['neta']);
        } finally { $s->drop(); }
    }

    public function testPaymentMethodAndFulfillmentFilters(): void
    {
        $s = $this->db();
        try {
            [$svc, , $u1] = $this->seed($s);
            $r = $svc->report($this->q($svc, ['medio' => 'transfer']), $u1);
            $this->assertSame(2, $r['totals']['orders'], 'payment_method snapshot: NULL (unpaid) never matches');
            $this->assertSame(18000, $r['totals']['bruta']);
            $this->assertSame(0, (int)($svc->report($this->q($svc, ['medio' => 'cash']), $u1)['totals']['orders']));
            $r = $svc->report($this->q($svc, ['modalidad' => 'pickup']), $u1);
            $this->assertSame(2, $r['totals']['orders']);
            $this->assertSame(13000, $r['totals']['bruta']);
            $r = $svc->report($this->q($svc, ['modalidad' => 'delivery']), $u1);
            $this->assertSame(2, $r['totals']['orders']);
            $this->assertSame(13000, $r['totals']['bruta']);
        } finally { $s->drop(); }
    }

    public function testBranchScopeIsolationAndOutOfScopeYieldsZero(): void
    {
        $s = $this->db();
        try {
            [$svc, , $u1, $u2] = $this->seed($s);
            $this->assertSame([['id' => 1, 'name' => 'Main']], $svc->branchesFor($u1));
            $this->assertSame([['id' => 2, 'name' => 'Norte']], $svc->branchesFor($u2));
            $r = $svc->report($this->q($svc), $u2);
            $this->assertSame(1, $r['totals']['orders'], 'branch-2 operator sees only her branch');
            $this->assertSame(50000, $r['totals']['bruta']);
            $this->assertSame(50000, $r['totals']['neta']);
            $r = $svc->report($this->q($svc, ['branch_id' => '2']), $u1);
            $this->assertSame([], $r['rows'], 'out-of-scope branch yields zero rows, never the other branch (R7)');
            $this->assertSame(['orders' => 0, 'bruta' => 0, 'descuentos' => 0, 'delivery_cobrado' => 0, 'remuneracion' => 0, 'neta' => 0], $r['totals']);
            $this->assertSame(0, (int)($svc->report($this->q($svc, ['branch_id' => '1']), $u2)['totals']['orders']));
        } finally { $s->drop(); }
    }

    public function testEmptyRangeYieldsZeroTotalsRowNot(): void
    {
        $s = $this->db();
        try {
            [$svc, , $u1] = $this->seed($s);
            $r = $svc->report($this->q($svc, ['from' => '2030-01-01', 'to' => '2030-01-02']), $u1);
            $this->assertSame([], $r['rows']);
            $this->assertSame(['orders' => 0, 'bruta' => 0, 'descuentos' => 0, 'delivery_cobrado' => 0, 'remuneracion' => 0, 'neta' => 0], $r['totals'], 'explicit zero row, never null (R5)');
        } finally { $s->drop(); }
    }

    public function testServiceValidationPresetsRollupAndMoney(): void
    {
        $s = $this->db();
        try {
            $svc = new ReportsService(new ReportsRepository($s->connection()), $s->connection());
            $today = date('Y-m-d');
            $f = $svc->parseFilters([], 1); // no preset → default 30d (R2)
            $this->assertSame('30d', $f['preset']);
            $this->assertSame(date('Y-m-d', strtotime('-29 days')), $f['from']);
            $this->assertSame($today, $f['to']);
            $this->assertSame(['preset' => 'hoy', 'from' => $today, 'to' => $today], array_intersect_key($svc->parseFilters(['preset' => 'hoy'], 1), array_flip(['preset', 'from', 'to'])));
            $f = $svc->parseFilters(['preset' => '7d'], 1);
            $this->assertSame(date('Y-m-d', strtotime('-6 days')), $f['from']);
            $f = $svc->parseFilters(['preset' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-10'], 1);
            $this->assertSame('custom', $f['preset']);
            $this->assertSame('2026-08-01', $f['from']);
            $this->assertSame('2026-08-10', $f['to']);
            foreach ([ // invalid ranges fall back to the default preset, never error
                ['preset' => 'custom', 'from' => '2026-08-10', 'to' => '2026-08-01'],   // reversed
                ['preset' => 'custom', 'from' => '2025-01-01', 'to' => '2026-06-01'],   // span > 366d
                ['preset' => 'custom', 'from' => '2026-13-40', 'to' => '2026-08-01'],   // not a date
                ['preset' => 'custom', 'to' => '2026-08-01'],                           // missing from
                ['preset' => 'nope', 'from' => '2026-08-01'],                           // unknown preset, stray dates
            ] as $bad) $this->assertSame('30d', $svc->parseFilters($bad, 1)['preset'], json_encode($bad));
            // Dimension filters: unknown values are ignored (filter absent).
            $f = $svc->parseFilters(['estado' => 'nope', 'modalidad' => 'carrier', 'producto' => 'abc', 'branch_id' => 'x', 'categoria' => '-3', 'medio' => str_repeat('a', 65)], 1);
            foreach (['status', 'fulfillment', 'productId', 'branchId', 'categoryId', 'paymentMethod'] as $k) $this->assertSame(null, $f[$k] ?? null, "$k must be absent for invalid input");
            $this->assertSame(null, $svc->parseFilters(['producto' => '0'], 1)['productId'], 'zero id is not a filter');
            $f = $svc->parseFilters(['estado' => 'pending', 'modalidad' => 'delivery', 'producto' => '7', 'categoria' => '9', 'branch_id' => '3', 'medio' => '  mercadopago  '], 1);
            $this->assertSame(['branchId' => 3, 'productId' => 7, 'categoryId' => 9, 'paymentMethod' => 'mercadopago', 'fulfillment' => 'delivery', 'status' => 'pending'], array_intersect_key($f, array_flip(['status', 'fulfillment', 'productId', 'categoryId', 'branchId', 'paymentMethod'])));
            // Canonical math + rollup + display, integer cents only.
            $this->assertSame(930, $svc->neta(1000, 100, 50, 20));
            $this->assertSame(['orders' => 3, 'bruta' => 1200, 'descuentos' => 50, 'delivery_cobrado' => 100, 'remuneracion' => 30, 'neta' => 1220], $svc->totalsFor([
                ['orders' => 1, 'bruta' => 500, 'descuentos' => 50, 'delivery_cobrado' => 0, 'remuneracion' => 0],
                ['orders' => 2, 'bruta' => 700, 'descuentos' => 0, 'delivery_cobrado' => 100, 'remuneracion' => 30],
            ]));
            $this->assertSame(['orders' => 0, 'bruta' => 0, 'descuentos' => 0, 'delivery_cobrado' => 0, 'remuneracion' => 0, 'neta' => 0], $svc->totalsFor([]));
            $this->assertSame('$ 1.234,56', ReportsService::money(123456));
            $this->assertSame('$ 0,05', ReportsService::money(5));
            $this->assertSame('-$ 12,34', ReportsService::money(-1234));
        } finally { $s->drop(); }
    }

    public function testDashboardElevenMetricsScoped(): void
    {
        $s = $this->db();
        try {
            [, , $u1, $u2] = $this->seed($s);
            $repo = new ReportsRepository($s->connection());
            $d = $repo->dashboard($u1);
            $this->assertSame(18300, $d['ventas_hoy'], 'SUM(grand_total): 12000 + 3000 + 1000 + 2300 (ready order carries fee)');
            $this->assertSame(4, $d['pedidos_hoy'], 'cancelled/rejected excluded from today (A1)');
            $this->assertSame(4575, $d['ticket_promedio'], 'intdiv(ventas, pedidos)');
            $this->assertSame(2, $d['nuevos'], 'today pending + live pending (no date filter)');
            $this->assertSame(1, $d['preparando']);
            $this->assertSame(1, $d['listos']);
            $this->assertSame(2, $d['buscando_delivery'], 'deliveries pending + assigned');
            $this->assertSame(1, $d['en_camino'], 'picked_up only');
            $this->assertSame(2, $d['cancelados'], 'live counts carry no date filter: today t5 + range o5');
            $this->assertSame(2, $d['rechazados'], 'live counts carry no date filter: today t6 + range o3');
            $this->assertSame(1, $d['stock_bajo'], 'simple stock ≤ 0 in scoped branches only');
            $d2 = $repo->dashboard($u2);
            $this->assertSame(['ventas_hoy' => 0, 'pedidos_hoy' => 0, 'nuevos' => 0, 'buscando_delivery' => 0, 'en_camino' => 0, 'stock_bajo' => 1], array_intersect_key($d2, array_flip(['ventas_hoy', 'pedidos_hoy', 'nuevos', 'buscando_delivery', 'en_camino', 'stock_bajo'])), 'branch-2 operator: no branch-1 activity, her own depleted item counts');
        } finally { $s->drop(); }
    }

    // ---- helpers ----

    private function db(): ScratchDatabase
    {
        $s = ScratchDatabase::create('vo_rep12_test_');
        (new MigrationRunner($s->connection(), dirname(__DIR__) . '/api/database/migrations'))->run();
        (new InstallerSeeder($s->connection()))->seed(self::SEED);
        return $s;
    }

    /** Fixed 3-day query string; $get overrides any key. */
    private function q(ReportsService $svc, array $get = [], int $userId = 1): array
    {
        return $svc->parseFilters(array_merge(['preset' => 'custom', 'from' => self::FROM, 'to' => self::TO], $get), $userId);
    }

    /**
     * Branch 1 (id 1, seeded as Main for user 1) + branch 2 (Norte); catalog items A=1/B=2 (cat 1), C=3 (cat 2).
     * Fixed-range orders: o1 completed disc 1100 · o2 pending 2 lines of A fee 400/payout 150 · o3 REJECTED
     * · o4 change_proposed coupon 100 · o5 CANCELLED · o6 completed item-promo 1000 fee 600/payout 250 ·
     * o7 branch 2 gross 50000. Today's live orders + deliveries + stock feed the dashboard test.
     *
     * @return array{0:ReportsService, 1:array{A:int,B:int,C:int}, 2:int, 3:int} [service, item ids, user1, user2]
     */
    private function seed(ScratchDatabase $s): array
    {
        $s->pdo->exec("INSERT INTO branches (business_id,name,is_active) VALUES (1,'Norte',1)");
        $s->pdo->exec("INSERT INTO categories (name,slug) VALUES ('R1','cat-r1'),('R2','cat-r2')");
        $s->pdo->exec("INSERT INTO catalog_items (category_id,type,name,slug,base_price_cents) VALUES (1,'product','Alfa','alfa-rep',5000),(1,'product','Brownie','brownie-rep',5000),(2,'product','Torta','torta-rep',8000)");
        $s->pdo->exec("INSERT INTO branch_items (branch_id,item_id,stock_mode,stock_quantity) VALUES (1,1,'simple',0),(1,2,'simple',5),(1,3,'none',0),(2,1,'simple',0)");
        [$o1, $o2, , $o4, , $o6] = [
            $this->order($s, ['created_at' => self::FROM . ' 12:00:00', 'gross' => 10000, 'item_promo' => 500, 'order_promo' => 300, 'coupon' => 200, 'pay_disc' => 100]),
            $this->order($s, ['status' => 'pending', 'fulfillment' => 'delivery', 'payment_method' => null, 'created_at' => self::FROM . ' 18:00:00', 'gross' => 5000, 'fee' => 400, 'payout' => 150]),
            $this->order($s, ['status' => 'rejected', 'fulfillment' => 'delivery', 'created_at' => '2026-08-11 13:00:00', 'gross' => 7000, 'fee' => 300, 'payout' => 100]),
            $this->order($s, ['status' => 'change_proposed', 'payment_method' => 'mercadopago', 'created_at' => '2026-08-11 11:00:00', 'gross' => 3000, 'coupon' => 100]),
            $this->order($s, ['status' => 'cancelled', 'fulfillment' => 'delivery', 'created_at' => '2026-08-12 09:00:00', 'gross' => 9999, 'fee' => 500, 'payout' => 200]),
            $this->order($s, ['fulfillment' => 'delivery', 'created_at' => '2026-08-12 20:00:00', 'gross' => 8000, 'item_promo' => 1000, 'fee' => 600, 'payout' => 250]),
            $this->order($s, ['branch_id' => 2, 'created_at' => self::FROM . ' 15:00:00', 'gross' => 50000]),
        ];
        $this->itemLine($s, $o1, 1, 5000);
        $this->itemLine($s, $o1, 2, 5000);
        $this->itemLine($s, $o2, 1, 2500);
        $this->itemLine($s, $o2, 1, 2500); // two lines, same product → EXISTS must not double-count
        $this->itemLine($s, $o4, 2, 3000);
        $this->itemLine($s, $o6, 3, 8000);
        // Live orders for the dashboard: ventas 12000+3000+1000+2300, pedidos 4; today = outside the fixed range.
        $t4 = $this->order($s, ['status' => 'ready', 'fulfillment' => 'delivery', 'created_at' => date('Y-m-d 11:00:00'), 'gross' => 2000, 'fee' => 300, 'payout' => 100]);
        $this->order($s, ['created_at' => date('Y-m-d 10:00:00'), 'gross' => 12000]);
        $t2 = $this->order($s, ['status' => 'pending', 'created_at' => date('Y-m-d 10:30:00'), 'gross' => 3000]);
        $this->order($s, ['status' => 'in_progress', 'created_at' => date('Y-m-d 10:45:00'), 'gross' => 1000]);
        $this->order($s, ['status' => 'cancelled', 'created_at' => date('Y-m-d 11:15:00'), 'gross' => 500]);
        $this->order($s, ['status' => 'rejected', 'created_at' => date('Y-m-d 11:30:00'), 'gross' => 700]);
        $dr = new DeliveryRepository($s->connection());
        $dr->createPendingForOrder($o2); // buscando delivery (pending)
        $dr->createPendingForOrder($t4);
        $s->pdo->exec("UPDATE deliveries SET state='assigned' WHERE order_id=$t4"); // buscando (assigned)
        $dr->createPendingForOrder($o6);
        $s->pdo->exec("UPDATE deliveries SET state='picked_up' WHERE order_id=$o6"); // en camino
        $s->pdo->exec("INSERT INTO users (business_id,name,email,password_hash) VALUES (1,'Norte Op','norte-op@rep.test','hash')");
        $u2 = (int)$s->pdo->lastInsertId();
        $s->pdo->exec("INSERT INTO user_branches (user_id,branch_id) VALUES ($u2,2)");
        return [new ReportsService(new ReportsRepository($s->connection()), $s->connection()), ['A' => 1, 'B' => 2, 'C' => 3], 1, $u2];
    }

    private function order(ScratchDatabase $s, array $o): int
    {
        static $n = 0;
        $o += ['branch_id' => 1, 'status' => 'completed', 'fulfillment' => 'pickup', 'payment_method' => 'transfer', 'created_at' => self::FROM . ' 12:00:00', 'gross' => 0, 'item_promo' => 0, 'order_promo' => 0, 'coupon' => 0, 'pay_disc' => 0, 'fee' => 0, 'payout' => 0];
        $merch = $o['gross'] - $o['item_promo'] - $o['order_promo'] - $o['coupon'] - $o['pay_disc'];
        $pm = $o['payment_method'] === null ? 'NULL' : $s->pdo->quote((string)$o['payment_method']);
        $s->pdo->exec("INSERT INTO orders (business_id,branch_id,number,status,public_token,fulfillment,payment_method,customer_name,gross_items_cents,item_promotions_cents,order_promotions_cents,coupon_discount_cents,payment_discount_cents,merchandise_total_cents,delivery_fee_cents,delivery_payout_cents,grand_total_cents,created_at) VALUES (1,'{$o['branch_id']}','R-" . str_pad((string)++$n, 6, '0', STR_PAD_LEFT) . "','{$o['status']}','" . bin2hex(random_bytes(32)) . "','{$o['fulfillment']}',$pm,'C','{$o['gross']}','{$o['item_promo']}','{$o['order_promo']}','{$o['coupon']}','{$o['pay_disc']}','$merch','{$o['fee']}','{$o['payout']}','" . ($merch + $o['fee']) . "','{$o['created_at']}')");
        return (int)$s->pdo->lastInsertId();
    }

    private function itemLine(ScratchDatabase $s, int $orderId, int $itemId, int $grossLine): void
    {
        $s->pdo->exec("INSERT INTO order_items (order_id,branch_id,item_id,item_name,quantity,unit_price_cents,gross_unit_cents,gross_line_cents,line_total_cents) SELECT $orderId,branch_id,$itemId,'Item',1,$grossLine,$grossLine,$grossLine,$grossLine FROM orders WHERE id=$orderId");
    }
}
