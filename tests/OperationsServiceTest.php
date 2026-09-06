<?php declare(strict_types=1);
namespace Tests;

use DomainException; use InvalidArgumentException; use PDO; use Throwable; use VO\Audit\AuditService; use VO\Database\Connection; use VO\Database\MigrationRunner; use VO\Domain\InvalidTransition; use VO\Inventory\StockService; use VO\Installer\InstallerSeeder; use VO\Orders\OrderOperationsService; use VO\Orders\OrderRepository; use VO\Payments\PaymentRepository; use VO\Payments\PaymentService;

final class OperationsServiceTest extends TestCase
{
    private const SEED = ['business_name' => 'Ops', 'business_slug' => 'ops', 'branch_name' => 'Main', 'branch_address' => '1 St', 'branch_phone' => '555', 'timezone' => 'America/Argentina/Buenos_Aires', 'admin_name' => 'Owner', 'admin_email' => 'owner@ops.test', 'admin_password' => 'change-me-now'];

    public function testKitchenChainAcceptPrepareReadyCompleteAuditsEachStep(): void
    {
        $s = $this->db();
        try {
            $gw = $this->gateway($s); $id = $this->order($s);
            foreach ([['accepted', 'pending'], ['in_progress', 'accepted'], ['ready', 'in_progress'], ['completed', 'ready']] as [$to, $from]) {
                $this->assertSame($to, (string)$gw->transition($id, $to, 1)['status']);
                $row = $s->pdo->query("SELECT request_id,metadata_json FROM audit_log WHERE action='orders.$to' AND entity_id=$id")->fetch(PDO::FETCH_ASSOC);
                $this->assertTrue((bool)$row, "missing audit orders.$to");
                $meta = json_decode((string)$row['metadata_json'], true);
                $this->assertSame($from, (string)$meta['from']); $this->assertSame($to, (string)$meta['to']);
                $this->assertSame('ops-req', (string)$row['request_id']);
            }
        } finally { $s->drop(); }
    }

    public function testIllegalTransitionRejectedWithoutSideEffects(): void
    {
        $s = $this->db();
        try {
            $gw = $this->gateway($s); $id = $this->order($s);
            $gw->transition($id, 'accepted', 1); $gw->transition($id, 'in_progress', 1); $gw->transition($id, 'ready', 1); $gw->transition($id, 'completed', 1);
            $movements = $this->count($s, 'stock_movements');
            $this->assertThrows(InvalidTransition::class, fn() => $gw->transition($id, 'accepted', 1));
            $this->assertSame('completed', $this->status($s, $id));
            $this->assertSame($movements, $this->count($s, 'stock_movements'));
            $this->assertSame(1, $this->count($s, "audit_log WHERE action='orders.accepted' AND entity_id=$id"));
            $this->assertThrows(InvalidArgumentException::class, fn() => $gw->transition($id, 'not_a_state', 1));
        } finally { $s->drop(); }
    }

    public function testPermissionDenialAuditedAndOrderUntouched(): void
    {
        $s = $this->db();
        try {
            $gw = $this->gateway($s); $id = $this->order($s);
            $this->seedLine($s, $id, 3, 10, 3);
            $s->pdo->exec("INSERT INTO users (business_id,name,email,password_hash) VALUES (1,'Limited','limited@ops.test','hash')");
            $peon = (int)$s->pdo->lastInsertId();
            $this->assertThrows(DomainException::class, fn() => $gw->transition($id, 'accepted', $peon));
            $this->assertSame('pending', $this->status($s, $id));
            $bi = $this->stock($s, 1);
            $this->assertSame(10, (int)$bi['stock_quantity']); $this->assertSame(3, (int)$bi['reserved_quantity']);
            $denial = $s->pdo->query("SELECT actor_id FROM audit_log WHERE action='authz.denied' AND entity_id=$id")->fetch(PDO::FETCH_ASSOC);
            $this->assertTrue((bool)$denial, 'authz.denied must be audited');
            $this->assertSame($peon, (int)$denial['actor_id']);
            // Owner holds every seeded permission: same transition succeeds for user 1.
            $this->assertSame('accepted', (string)$gw->transition($id, 'accepted', 1)['status']);
        } finally { $s->drop(); }
    }

    public function testAcceptConsumesReservedStockExactlyOnceAndRepeatsAreSafe(): void
    {
        $s = $this->db();
        try {
            $gw = $this->gateway($s); $id = $this->order($s);
            $this->seedLine($s, $id, 3, 10, 3);
            $gw->transition($id, 'accepted', 1);
            $bi = $this->stock($s, 1);
            $this->assertSame(7, (int)$bi['stock_quantity']); $this->assertSame(0, (int)$bi['reserved_quantity']);
            $mv = $s->pdo->query("SELECT quantity_delta FROM stock_movements WHERE order_id=$id AND reason='consume_accepted'")->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame(1, count($mv)); $this->assertSame(-3, (int)$mv[0]['quantity_delta']);
            $this->assertThrows(InvalidTransition::class, fn() => $gw->transition($id, 'accepted', 1));
            $this->assertSame(1, $this->count($s, "stock_movements WHERE order_id=$id AND reason='consume_accepted'"));
            $this->assertSame(7, (int)$this->stock($s, 1)['stock_quantity']);
            // Payment-driven path after a manual accept: plain no-op, no duplicate consume.
            $this->assertSame('accepted', (string)$gw->acceptFromPayment($id)['status']);
            $this->assertSame(1, $this->count($s, "stock_movements WHERE order_id=$id AND reason='consume_accepted'"));
        } finally { $s->drop(); }
    }

    public function testMovementReasonAcceptsConsumeAcceptedAndStillRejectsManual(): void
    {
        $s = $this->db();
        try {
            $this->seedLine($s, $this->order($s), 1, 5, 0);
            $s->pdo->exec("INSERT INTO stock_movements (business_id,branch_id,order_id,item_id,quantity_delta,reason) VALUES (1,1,1,1,-1,'consume_accepted')");
            $this->assertSame(1, $this->count($s, "stock_movements WHERE reason='consume_accepted'"));
            $this->assertThrows(Throwable::class, fn() => $s->pdo->exec("INSERT INTO stock_movements (business_id,branch_id,item_id,quantity_delta,reason) VALUES (1,1,1,1,'manual')"));
        } finally { $s->drop(); }
    }

    public function testCancelRequiresNonEmptyReason(): void
    {
        $s = $this->db();
        try {
            $gw = $this->gateway($s); $id = $this->order($s);
            $this->seedLine($s, $id, 3, 10, 3);
            foreach ([null, '', '   '] as $bad) $this->assertThrows(InvalidArgumentException::class, fn() => $gw->transition($id, 'cancelled', 1, $bad));
            $this->assertSame('pending', $this->status($s, $id));
            $this->assertSame(0, $this->count($s, "stock_movements WHERE order_id=$id AND reason='release_cancelled'"));
            $this->assertSame(3, (int)$this->stock($s, 1)['reserved_quantity']);
        } finally { $s->drop(); }
    }

    public function testCancelReleasesStockAndCancelsPendingPayment(): void
    {
        $s = $this->db();
        try {
            $gw = $this->gateway($s); $id = $this->order($s, method: 'transfer');
            $this->seedLine($s, $id, 3, 10, 3);
            $this->payment($s, $id, 'pending_verification');
            $gw->transition($id, 'cancelled', 1, 'cliente se arrepintió');
            $this->assertSame('cancelled', $this->status($s, $id));
            $bi = $this->stock($s, 1);
            $this->assertSame(10, (int)$bi['stock_quantity']); $this->assertSame(0, (int)$bi['reserved_quantity']);
            $this->assertSame(1, $this->count($s, "stock_movements WHERE order_id=$id AND reason='release_cancelled' AND quantity_delta=-3"));
            $this->assertSame('cancelled', $this->paymentState($s, $id));
            $row = $s->pdo->query("SELECT metadata_json,request_id FROM audit_log WHERE action='orders.cancelled' AND entity_id=$id")->fetch(PDO::FETCH_ASSOC);
            $meta = json_decode((string)$row['metadata_json'], true);
            $this->assertSame('cliente se arrepintió', (string)$meta['reason']);
            $this->assertSame('pending', (string)$meta['from']);
            $this->assertSame('ops-req', (string)$row['request_id']);
        } finally { $s->drop(); }
    }

    public function testCancelWithApprovedPaymentStaysApprovedAndAuditsRefundRequired(): void
    {
        $s = $this->db();
        try {
            $gw = $this->gateway($s); $id = $this->order($s, method: 'mercadopago');
            $this->seedLine($s, $id, 2, 5, 2);
            $this->payment($s, $id, 'approved', 'mercadopago');
            $gw->transition($id, 'accepted', 1);
            $gw->transition($id, 'cancelled', 1, ' Cliente pidió anular ');
            $this->assertSame('approved', $this->paymentState($s, $id));
            $this->assertSame('cancelled', $this->status($s, $id));
            $meta = json_decode((string)$s->pdo->query("SELECT metadata_json FROM audit_log WHERE action='orders.cancelled' AND entity_id=$id")->fetchColumn(), true);
            $this->assertTrue(($meta['refund_required'] ?? null) === true, 'refund_required flag missing');
            $this->assertSame('Cliente pidió anular', (string)$meta['reason']);
        } finally { $s->drop(); }
    }

    public function testRejectReleasesReservedStockWithReleaseRejected(): void
    {
        $s = $this->db();
        try {
            $gw = $this->gateway($s); $id = $this->order($s);
            $this->seedLine($s, $id, 2, 8, 2);
            $gw->transition($id, 'rejected', 1, 'datos del cliente inconsistentes');
            $this->assertSame('rejected', $this->status($s, $id));
            $bi = $this->stock($s, 1);
            $this->assertSame(0, (int)$bi['reserved_quantity']); $this->assertSame(8, (int)$bi['stock_quantity']);
            $this->assertSame(1, $this->count($s, "stock_movements WHERE order_id=$id AND reason='release_rejected'"));
            $this->assertSame(1, $this->count($s, "audit_log WHERE action='orders.rejected' AND entity_id=$id"));
        } finally { $s->drop(); }
    }

    public function testExpirySweepExpiresStaleWithStockReleaseAndPaymentCancelLeavesFresh(): void
    {
        $s = $this->db();
        try {
            $gw = $this->gateway($s);
            $stalePending = $this->order($s, 'B-000001');
            $this->seedLine($s, $stalePending, 3, 10, 3);
            $this->payment($s, $stalePending, 'pending_verification');
            $staleProposed = $this->order($s, 'B-000002');
            $s->pdo->exec("UPDATE orders SET status='change_proposed' WHERE id=$staleProposed");
            $fresh = $this->order($s, 'B-000003');
            $this->seedLine($s, $fresh, 1, 5, 1);
            $s->pdo->exec("UPDATE orders SET created_at=DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE id IN ($stalePending,$staleProposed)");
            $this->assertSame(2, $gw->expireStaleLazy());
            $this->assertSame('expired', $this->status($s, $stalePending));
            $this->assertSame('expired', $this->status($s, $staleProposed));
            $this->assertSame('pending', $this->status($s, $fresh));
            $bi = $this->stock($s, 1);
            $this->assertSame(0, (int)$bi['reserved_quantity']); $this->assertSame(10, (int)$bi['stock_quantity']);
            $this->assertSame(1, $this->count($s, "stock_movements WHERE reason='release_expired'"));
            $this->assertSame(1, (int)$s->pdo->query('SELECT reserved_quantity FROM branch_items WHERE item_id=2')->fetchColumn(), 'fresh order must keep its reservation');
            $this->assertSame('cancelled', $this->paymentState($s, $stalePending));
            $this->assertSame(2, $this->count($s, "audit_log WHERE action='orders.expired'"));
            $this->assertSame(0, $gw->expireStaleLazy());
        } finally { $s->drop(); }
    }

    public function testExpirySweepHonorsCustomTtlSetting(): void
    {
        $s = $this->db();
        try {
            $gw = $this->gateway($s);
            $s->pdo->exec("INSERT INTO business_settings (business_id,setting_key,setting_value) VALUES (1,'orders.order_expiry_hours','1')");
            $stale = $this->order($s);
            $this->seedLine($s, $stale, 1, 4, 1);
            $fresh = $this->order($s, 'B-000002');
            $s->pdo->exec("UPDATE orders SET created_at=DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id=$stale");
            $this->assertSame(1, $gw->expireStaleLazy());
            $this->assertSame('expired', $this->status($s, $stale));
            $this->assertSame('pending', $this->status($s, $fresh));
            $bi = $this->stock($s, 1);
            $this->assertSame(0, (int)$bi['reserved_quantity'], 'stale reservation must be released');
            $this->assertSame(4, (int)$bi['stock_quantity']);
            $this->assertSame(1, $this->count($s, "stock_movements WHERE order_id=$stale AND reason='release_expired'"));
            $this->assertSame(1, $this->count($s, "audit_log WHERE action='orders.expired' AND entity_id=$stale"));
        } finally { $s->drop(); }
    }

    public function testAcceptFromPaymentRunsInsideActiveTransactionAndConsumes(): void
    {
        $s = $this->db();
        try {
            $db = $s->connection(); // one shared wrapper: the gateway and payment service MUST see the same transaction
            $gw = $this->gateway($s, $db); $pay = $this->paymentService($s, $db);
            $id = $this->order($s, method: 'transfer');
            $this->seedLine($s, $id, 2, 6, 2);
            $second = $this->order($s, 'B-000002', method: 'transfer');
            $result = $db->transaction(fn(): array => ['viaGateway' => $gw->acceptFromPayment($id), 'viaPayment' => $pay->autoAcceptOrder($second)]);
            $this->assertSame('accepted', (string)$result['viaGateway']['status']);
            $this->assertSame('accepted', (string)$result['viaPayment']['status']);
            $bi = $this->stock($s, 1);
            $this->assertSame(4, (int)$bi['stock_quantity']); $this->assertSame(0, (int)$bi['reserved_quantity']);
            $this->assertSame(1, $this->count($s, "stock_movements WHERE order_id=$id AND reason='consume_accepted'"));
            $this->assertSame(1, $this->count($s, "audit_log WHERE action='orders.accepted' AND entity_id=$id"));
        } finally { $s->drop(); }
    }

    public function testBoardRowsScopesByOperatorBranchesAndFilter(): void
    {
        $s = $this->db();
        try {
            $this->gateway($s);
            $inBranch = $this->order($s, 'B-000001');
            $other = $this->order($s, 'B-000002');
            $s->pdo->exec("INSERT INTO branches (id,business_id,name,is_active) VALUES (2,1,'Second',1)");
            $s->pdo->exec("UPDATE orders SET branch_id=2 WHERE id=$other");
            $repo = new OrderRepository($s->connection());
            $rows = $repo->boardRows(1);
            $this->assertSame(1, count($rows)); $this->assertSame($inBranch, (int)$rows[0]['id']);
            $this->assertSame('Main', (string)$rows[0]['branch_name']);
            $this->assertSame(0, count($repo->boardRows(1, 2)), 'branch filter must stay scoped to user_branches');
            $s->pdo->exec('INSERT INTO user_branches (user_id,branch_id) VALUES (1,2)');
            $rows = $repo->boardRows(1, 2);
            $this->assertSame(1, count($rows)); $this->assertSame($other, (int)$rows[0]['id']);
            // Completed orders leave the board.
            $gw = $this->gateway($s);
            $gw->transition($inBranch, 'accepted', 1); $gw->transition($inBranch, 'in_progress', 1); $gw->transition($inBranch, 'ready', 1); $gw->transition($inBranch, 'completed', 1);
            $this->assertSame(0, count($repo->boardRows(1, 1)));
        } finally { $s->drop(); }
    }

    // ---- helpers ----

    private function db(): ScratchDatabase
    {
        $s = ScratchDatabase::create('vo_ops9_test_');
        (new MigrationRunner($s->connection(), dirname(__DIR__) . '/api/database/migrations'))->run();
        (new InstallerSeeder($s->connection()))->seed(self::SEED);
        return $s;
    }

    private function gateway(ScratchDatabase $s, ?Connection $db = null): OrderOperationsService
    {
        $db ??= $s->connection();
        return new OrderOperationsService($db, new OrderRepository($db), new StockService($db), new PaymentRepository($db), $this->paymentService($s, $db), new AuditService($s->pdo), 'ops-req');
    }

    private function paymentService(ScratchDatabase $s, ?Connection $db = null): PaymentService
    {
        $db ??= $s->connection();
        return new PaymentService($db, new PaymentRepository($db), new OrderRepository($db), new AuditService($s->pdo), 'ops-req');
    }

    private function order(ScratchDatabase $s, string $number = 'B-000001', string $status = 'pending', int $branchId = 1, string $method = 'cash'): int
    {
        $s->pdo->exec("INSERT INTO orders (business_id,branch_id,number,status,public_token,fulfillment,payment_method,customer_name,gross_items_cents,item_promotions_cents,order_promotions_cents,coupon_discount_cents,payment_discount_cents,merchandise_total_cents,delivery_fee_cents,grand_total_cents) VALUES (1,$branchId," . $s->pdo->quote($number) . ',' . $s->pdo->quote($status) . ",'" . bin2hex(random_bytes(32)) . "','pickup'," . $s->pdo->quote($method) . ",'C',1500,0,0,0,0,1500,0,1500)");
        return (int)$s->pdo->lastInsertId();
    }

    private function seedLine(ScratchDatabase $s, int $orderId, int $qty, int $stock, int $reserved): void
    {
        $s->pdo->exec("INSERT IGNORE INTO categories (id,name,slug) VALUES (1,'Food','food')");
        $slug = 'burger-' . bin2hex(random_bytes(4));
        $s->pdo->exec("INSERT INTO catalog_items (category_id,type,name,slug,base_price_cents,requires_variant,allows_delivery,is_active) VALUES (1,'product','Burger','$slug',1000,0,1,1)");
        $item = (int)$s->pdo->lastInsertId();
        $s->pdo->exec("INSERT INTO item_variants (item_id,name,price_cents,is_active) VALUES ($item,'Default',1000,1)");
        $variant = (int)$s->pdo->lastInsertId();
        $s->pdo->exec("INSERT INTO branch_items (branch_id,item_id,is_available,stock_mode,stock_quantity,reserved_quantity) VALUES (1,$item,1,'simple',$stock,$reserved)");
        $s->pdo->exec("INSERT INTO branch_variants (branch_id,variant_id,is_available) VALUES (1,$variant,1)");
        $s->pdo->exec("INSERT INTO order_items (order_id,branch_id,item_id,variant_id,item_name,variant_name,quantity,unit_price_cents,modifiers_total_cents,gross_unit_cents,gross_line_cents,line_total_cents) VALUES ($orderId,1,$item,$variant,'Burger','Default',$qty,1000,0,1000,1000,1000)");
    }

    private function payment(ScratchDatabase $s, int $orderId, string $state, string $method = 'transfer'): int
    {
        $s->pdo->exec("INSERT INTO payments (order_id,business_id,method,state,amount_cents,currency) VALUES ($orderId,1," . $s->pdo->quote($method) . ',' . $s->pdo->quote($state) . ",1500,'ARS')");
        return (int)$s->pdo->lastInsertId();
    }

    private function status(ScratchDatabase $s, int $orderId): string { return (string)$s->pdo->query('SELECT status FROM orders WHERE id=' . $orderId)->fetchColumn(); }
    private function paymentState(ScratchDatabase $s, int $orderId): string { return (string)$s->pdo->query('SELECT state FROM payments WHERE order_id=' . $orderId)->fetchColumn(); }
    private function stock(ScratchDatabase $s, int $itemId): array { return $s->pdo->query('SELECT stock_quantity,reserved_quantity FROM branch_items WHERE item_id=' . $itemId)->fetch(PDO::FETCH_ASSOC); }
    private function count(ScratchDatabase $s, string $from): int { return (int)$s->pdo->query('SELECT COUNT(*) FROM ' . $from)->fetchColumn(); }
}
