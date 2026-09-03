<?php declare(strict_types=1);
namespace Tests;

use DomainException; use InvalidArgumentException; use PDO; use Throwable; use VO\Audit\AuditService; use VO\Database\Connection; use VO\Database\MigrationRunner; use VO\Delivery\DeliveryRepository; use VO\Delivery\DeliveryService; use VO\Delivery\Pin; use VO\Domain\InvalidTransition; use VO\Installer\InstallerSeeder; use VO\Inventory\StockService; use VO\Orders\OrderOperationsService; use VO\Orders\OrderRepository; use VO\Payments\PaymentRepository; use VO\Payments\PaymentService;

final class DeliveryServiceTest extends TestCase
{
    private const SEED = ['business_name' => 'Del', 'business_slug' => 'del', 'branch_name' => 'Main', 'branch_address' => '1 St', 'branch_phone' => '555', 'timezone' => 'America/Argentina/Buenos_Aires', 'admin_name' => 'Owner', 'admin_email' => 'owner@del.test', 'admin_password' => 'change-me-now'];

    public function testHappyPathAssignPickupDeliverConsumesPinAndCompletesOrder(): void
    {
        $s = $this->db();
        try {
            $svc = $this->svc($s); $gw = $this->gateway($s); $orderId = $this->deliveryOrder($s, 'B-000001', 'accepted');
            $deliveryId = $this->deliveryId($s, $orderId); $personId = $this->person($s);
            $pin = $this->pin($s, $deliveryId, $orderId);
            $gw->transition($orderId, 'in_progress', 1); $gw->transition($orderId, 'ready', 1);
            $assigned = $svc->assign($deliveryId, $personId, 1);
            $this->assertSame('assigned', (string)$assigned['state']); $this->assertSame($personId, (int)$assigned['delivery_person_id']);
            $this->assertSame(Pin::hash($pin), (string)$assigned['pin_hash'], 'stored pin_hash must match the deterministic code');
            $this->assertSame(0, (int)$assigned['pin_attempts']);
            $this->assertNotSame('', (string)$assigned['assigned_at']);
            $this->assertSame(1, $this->audits($s, 'deliveries.assigned', $deliveryId));
            $svc->markPickedUp($deliveryId, 1);
            $row = $this->row($s, $deliveryId);
            $this->assertSame('picked_up', (string)$row['state']); $this->assertNotSame('', (string)$row['picked_up_at']);
            $this->assertSame(1, $this->audits($s, 'deliveries.picked_up', $deliveryId));
            $svc->deliver($deliveryId, $pin, 1);
            $row = $this->row($s, $deliveryId);
            $this->assertSame('delivered', (string)$row['state']); $this->assertNotSame('', (string)$row['delivered_at']);
            $this->assertSame(null, $row['pin_hash'], 'PIN must be consumed on success');
            $this->assertSame('completed', $this->orderStatus($s, $orderId));
            $this->assertSame(1, $this->audits($s, 'deliveries.delivered', $deliveryId));
            $this->assertSame(1, $this->audits($s, 'orders.completed', $orderId));
            $completedActor = $s->pdo->query("SELECT actor_type FROM audit_log WHERE action='orders.completed' AND entity_id=$orderId")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('system', (string)$completedActor['actor_type'], 'completion comes from the delivery driver, not an operator');
        } finally { $s->drop(); }
    }

    public function testWrongPinIncrementsAttemptsAndNextAttemptFailsWithPinExhausted(): void
    {
        $s = $this->db();
        try {
            $svc = $this->svc($s); $gw = $this->gateway($s); $orderId = $this->deliveryOrder($s, 'B-000001', 'accepted');
            $deliveryId = $this->deliveryId($s, $orderId); $personId = $this->person($s);
            $svc->assign($deliveryId, $personId, 1);
            $gw->transition($orderId, 'in_progress', 1); $gw->transition($orderId, 'ready', 1);
            $svc->markPickedUp($deliveryId, 1);
            for ($i = 1; $i <= 5; $i++) {
                $this->assertSame('PIN_INVALID', $this->codeOf(fn() => $svc->deliver($deliveryId, '000000', 1)));
                $this->assertSame($i, (int)$this->row($s, $deliveryId)['pin_attempts']);
                $this->assertSame('picked_up', (string)$this->row($s, $deliveryId)['state']);
            }
            // 6th attempt: attempts exhausted → the delivery fails with pin_exhausted (spec D7).
            $this->assertSame('PIN_EXHAUSTED', $this->codeOf(fn() => $svc->deliver($deliveryId, '000000', 1)));
            $row = $this->row($s, $deliveryId);
            $this->assertSame('failed', (string)$row['state']); $this->assertSame('pin_exhausted', (string)$row['failed_reason']);
            $this->assertSame(1, $this->audits($s, 'deliveries.failed', $deliveryId));
            // Order was never completed, and the terminal state rejects even the correct PIN.
            $pin = $this->pin($s, $deliveryId, $orderId);
            $this->assertThrows(InvalidTransition::class, fn() => $svc->deliver($deliveryId, $pin, 1));
            $this->assertSame('ready', $this->orderStatus($s, $orderId), 'exhaustion must not complete the order');
        } finally { $s->drop(); }
    }

    public function testDeliverRequiresReadyOrderAndLeavesStateUnchanged(): void
    {
        $s = $this->db();
        try {
            $svc = $this->svc($s); $gw = $this->gateway($s); $orderId = $this->deliveryOrder($s, 'B-000001', 'accepted');
            $deliveryId = $this->deliveryId($s, $orderId); $personId = $this->person($s);
            $svc->assign($deliveryId, $personId, 1); $svc->markPickedUp($deliveryId, 1);
            $pin = $this->pin($s, $deliveryId, $orderId);
            $this->assertSame('ORDER_NOT_READY', $this->codeOf(fn() => $svc->deliver($deliveryId, $pin, 1)));
            $row = $this->row($s, $deliveryId);
            $this->assertSame('picked_up', (string)$row['state']); $this->assertSame(0, (int)$row['pin_attempts']);
            $this->assertSame('accepted', $this->orderStatus($s, $orderId));
            $gw->transition($orderId, 'in_progress', 1); $gw->transition($orderId, 'ready', 1);
            $svc->deliver($deliveryId, $pin, 1);
            $this->assertSame('delivered', (string)$this->row($s, $deliveryId)['state']);
            $this->assertSame('completed', $this->orderStatus($s, $orderId));
        } finally { $s->drop(); }
    }

    public function testFailRequiresReasonAndRejectsTerminalStates(): void
    {
        $s = $this->db();
        try {
            $svc = $this->svc($s); $orderId = $this->deliveryOrder($s, 'B-000001', 'accepted');
            $deliveryId = $this->deliveryId($s, $orderId); $personId = $this->person($s);
            $svc->assign($deliveryId, $personId, 1);
            foreach (['', '   '] as $blank) $this->assertThrows(InvalidArgumentException::class, fn() => $svc->fail($deliveryId, $blank, 1));
            $this->assertSame('assigned', (string)$this->row($s, $deliveryId)['state']);
            $svc->fail($deliveryId, 'cliente ausente', 1);
            $row = $this->row($s, $deliveryId);
            $this->assertSame('failed', (string)$row['state']); $this->assertSame('cliente ausente', (string)$row['failed_reason']);
            $meta = json_decode((string)$s->pdo->query("SELECT metadata_json FROM audit_log WHERE action='deliveries.failed' AND entity_id=$deliveryId")->fetchColumn(), true);
            $this->assertSame('cliente ausente', (string)$meta['reason']);
            $this->assertThrows(InvalidTransition::class, fn() => $svc->fail($deliveryId, 'otra vez', 1));
        } finally { $s->drop(); }
    }

    public function testReassignUpdatesPersonAuditsAndIsBlockedAfterPickup(): void
    {
        $s = $this->db();
        try {
            $svc = $this->svc($s); $orderId = $this->deliveryOrder($s, 'B-000001', 'accepted');
            $deliveryId = $this->deliveryId($s, $orderId);
            $a = $this->person($s, 1, 'Repartidor A'); $b = $this->person($s, 1, 'Repartidor B');
            $svc->assign($deliveryId, $a, 1);
            $svc->reassign($deliveryId, $b, 1);
            $row = $this->row($s, $deliveryId);
            $this->assertSame('assigned', (string)$row['state']); $this->assertSame($b, (int)$row['delivery_person_id']);
            $this->assertSame(1, $this->audits($s, 'deliveries.reassigned', $deliveryId));
            // Reassign without deliveries.reassign is a typed denial with audit.
            $peon = $this->limitedUser($s);
            $this->assertSame('PERMISSION_DENIED', $this->codeOf(fn() => $svc->reassign($deliveryId, $a, $peon)));
            $this->assertSame(1, $this->count($s, "SELECT COUNT(*) FROM audit_log WHERE action='authz.denied' AND entity_id=$deliveryId AND metadata_json LIKE '%deliveries.reassign%'"));
            // Reassign blocked once picked_up.
            $svc->markPickedUp($deliveryId, 1);
            $this->assertThrows(InvalidTransition::class, fn() => $svc->reassign($deliveryId, $a, 1));
            $this->assertSame(1, $this->audits($s, 'deliveries.reassigned', $deliveryId), 'failed reassign must not audit success');
        } finally { $s->drop(); }
    }

    public function testOrderCancelCascadesActiveDeliveryInsideTheSameTransaction(): void
    {
        $s = $this->db();
        try {
            $svc = $this->svc($s); $gw = $this->gateway($s);
            $assignedOrder = $this->deliveryOrder($s, 'B-000001', 'accepted');
            $assignedId = $this->deliveryId($s, $assignedOrder);
            $svc->assign($assignedId, $this->person($s), 1);
            $pendingOrder = $this->deliveryOrder($s, 'B-000002', 'accepted');
            $pendingId = $this->deliveryId($s, $pendingOrder);
            $pickedOrder = $this->deliveryOrder($s, 'B-000003', 'accepted');
            $pickedId = $this->deliveryId($s, $pickedOrder);
            $svc->assign($pickedId, $this->person($s), 1); $svc->markPickedUp($pickedId, 1);
            $failedOrder = $this->deliveryOrder($s, 'B-000004', 'accepted');
            $failedId = $this->deliveryId($s, $failedOrder);
            $svc->assign($failedId, $this->person($s), 1); $svc->fail($failedId, 'dirección inexistente', 1);
            foreach ([$assignedOrder, $pendingOrder, $pickedOrder, $failedOrder] as $id) $this->assertSame('cancelled', (string)$gw->transition($id, 'cancelled', 1, 'cliente canceló')['status']);
            foreach ([['B-000001', $assignedId, 'assigned'], ['B-000002', $pendingId, 'pending'], ['B-000003', $pickedId, 'picked_up']] as [$number, $deliveryId, $from]) {
                $row = $this->row($s, $deliveryId);
                $this->assertSame('cancelled', (string)$row['state'], "$number delivery must cascade to cancelled");
                $meta = json_decode((string)$s->pdo->query("SELECT metadata_json FROM audit_log WHERE action='deliveries.cancelled' AND entity_id=$deliveryId")->fetchColumn(), true);
                $this->assertSame($from, (string)$meta['from']); $this->assertSame('order_cancelled', (string)$meta['cascade']);
            }
            $this->assertSame('failed', (string)$this->row($s, $failedId)['state'], 'terminal deliveries are not re-cancelled');
            $this->assertSame(0, $this->count($s, "SELECT COUNT(*) FROM audit_log WHERE action='deliveries.cancelled' AND entity_id=$failedId"));
            // Pickup order without a delivery row: cancel still works, nothing to cascade.
            $pickup = $this->pickupOrder($s);
            $this->assertSame('cancelled', (string)$gw->transition($pickup, 'cancelled', 1, 'sin entrega')['status']);
            $this->assertSame(3, $this->count($s, "SELECT COUNT(*) FROM audit_log WHERE action='deliveries.cancelled'"));
        } finally { $s->drop(); }
    }

    public function testDirectCancelRequiresManagePermission(): void
    {
        $s = $this->db();
        try {
            $svc = $this->svc($s); $orderId = $this->deliveryOrder($s, 'B-000001', 'accepted');
            $deliveryId = $this->deliveryId($s, $orderId);
            $peon = $this->limitedUser($s); // no roles → no deliveries.manage
            $this->assertSame('PERMISSION_DENIED', $this->codeOf(fn() => $svc->cancel($deliveryId, $peon)));
            $this->assertSame('pending', (string)$this->row($s, $deliveryId)['state']);
            $this->assertSame(1, $this->audits($s, 'authz.denied', $deliveryId));
            $svc->cancel($deliveryId, 1);
            $this->assertSame('cancelled', (string)$this->row($s, $deliveryId)['state']);
            $this->assertSame(1, $this->audits($s, 'deliveries.cancelled', $deliveryId));
        } finally { $s->drop(); }
    }

    public function testPermissionAndBranchScopeDenialsAreTypedAndAudited(): void
    {
        $s = $this->db();
        try {
            $svc = $this->svc($s); $orderId = $this->deliveryOrder($s, 'B-000001', 'accepted');
            $deliveryId = $this->deliveryId($s, $orderId); $personId = $this->person($s);
            $peon = $this->limitedUser($s);
            foreach (['assign' => fn() => $svc->assign($deliveryId, $personId, $peon),
                'pickup' => fn() => $svc->markPickedUp($deliveryId, $peon),
                'deliver' => fn() => $svc->deliver($deliveryId, '123456', $peon),
                'fail' => fn() => $svc->fail($deliveryId, 'motivo', $peon)] as $name => $fn) {
                $this->assertSame('PERMISSION_DENIED', $this->codeOf($fn), "$name without permission must be denied");
            }
            $this->assertSame('pending', (string)$this->row($s, $deliveryId)['state']);
            $this->assertSame(4, $this->count($s, "SELECT COUNT(*) FROM audit_log WHERE action='authz.denied' AND entity_id=$deliveryId AND metadata_json LIKE '%deliveries.assign%'"));
            // Operator with every permission but no branch link stays scoped out (user_branches gate).
            $s->pdo->exec("INSERT INTO users (business_id,name,email,password_hash) VALUES (1,'Scoped','scoped@del.test','hash')");
            $scoped = (int)$s->pdo->lastInsertId();
            $s->pdo->exec("INSERT INTO user_roles (user_id,role_id) SELECT $scoped, id FROM roles WHERE name='owner'");
            $this->assertSame('PERMISSION_DENIED', $this->codeOf(fn() => $svc->assign($deliveryId, $personId, $scoped)));
            $this->assertSame(1, $this->count($s, "SELECT COUNT(*) FROM audit_log WHERE action='authz.denied' AND entity_id=$deliveryId AND metadata_json LIKE '%branch%'"));
        } finally { $s->drop(); }
    }

    public function testAssignRejectsForeignPersonsPendingOrdersAndIllegalStates(): void
    {
        $s = $this->db();
        try {
            $svc = $this->svc($s);
            $s->pdo->exec("INSERT INTO branches (business_id,name,is_active) VALUES (1,'Norte',1)");
            $foreign = $this->person($s, 2, 'De otra sucursal'); // linked to branch 2 only
            $orderId = $this->deliveryOrder($s, 'B-000001', 'accepted');
            $deliveryId = $this->deliveryId($s, $orderId);
            $this->assertSame('PERSON_NOT_AVAILABLE', $this->codeOf(fn() => $svc->assign($deliveryId, $foreign, 1)));
            $this->assertSame('pending', (string)$this->row($s, $deliveryId)['state']);
            $inactive = $this->person($s, 1, 'Inactivo', 0);
            $this->assertSame('PERSON_NOT_AVAILABLE', $this->codeOf(fn() => $svc->assign($deliveryId, $inactive, 1)));
            // Assignment window: order must be accepted onward.
            $pendingOrder = $this->deliveryOrder($s, 'B-000002', 'pending');
            $pendingDelivery = $this->deliveryId($s, $pendingOrder);
            $local = $this->person($s, 1, 'Local');
            $this->assertSame('ORDER_NOT_ASSIGNABLE', $this->codeOf(fn() => $svc->assign($pendingDelivery, $local, 1)));
            // Deliver/pickup on a pending delivery are illegal transitions without side effects.
            $pin = $this->pin($s, $deliveryId, $orderId);
            $this->assertThrows(InvalidTransition::class, fn() => $svc->markPickedUp($deliveryId, 1));
            $this->assertThrows(InvalidTransition::class, fn() => $svc->deliver($deliveryId, $pin, 1));
            $this->assertSame('pending', (string)$this->row($s, $deliveryId)['state']);
            $this->assertSame(0, $this->count($s, "SELECT COUNT(*) FROM audit_log WHERE action LIKE 'deliveries.%' AND entity_id=$deliveryId"));
        } finally { $s->drop(); }
    }

    public function testPinIsDeterministicHashedAndGatedForRedisplay(): void
    {
        $s = $this->db();
        try {
            $svc = $this->svc($s); $gw = $this->gateway($s); $orderId = $this->deliveryOrder($s, 'B-000001', 'accepted');
            $deliveryId = $this->deliveryId($s, $orderId); $personId = $this->person($s);
            $key = (new DeliveryRepository($s->connection()))->ensurePinKey(1);
            $pin = Pin::code($key, $deliveryId, $orderId);
            $this->assertTrue(preg_match('/^\d{6}$/', $pin) === 1, 'PIN must be 6 digits, got ' . $pin);
            $this->assertSame($pin, Pin::code($key, $deliveryId, $orderId), 'PIN must be deterministic per delivery+order');
            $this->assertTrue(Pin::verify($pin, Pin::hash($pin)) && !Pin::verify('000000' === $pin ? '111111' : '000000', Pin::hash($pin)));
            $order = $s->pdo->query('SELECT * FROM orders WHERE id=' . $orderId)->fetch(PDO::FETCH_ASSOC);
            $this->assertSame(null, $svc->pinForOrder($order), 'no PIN while pending (spec D9)');
            $svc->assign($deliveryId, $personId, 1);
            $this->assertSame($pin, $svc->pinForOrder($order));
            $svc->markPickedUp($deliveryId, 1);
            $this->assertSame($pin, $svc->pinForOrder($order));
            $gw->transition($orderId, 'in_progress', 1); $gw->transition($orderId, 'ready', 1);
            $svc->deliver($deliveryId, $pin, 1);
            $this->assertSame(null, $svc->pinForOrder($order), 'no PIN once delivered');
        } finally { $s->drop(); }
    }

    // ---- helpers ----

    private function db(): ScratchDatabase
    {
        $s = ScratchDatabase::create('vo_del10_test_');
        (new MigrationRunner($s->connection(), dirname(__DIR__) . '/api/database/migrations'))->run();
        (new InstallerSeeder($s->connection()))->seed(self::SEED);
        return $s;
    }

    private function svc(ScratchDatabase $s, ?Connection $db = null): DeliveryService
    {
        $db ??= $s->connection();
        return new DeliveryService($db, new DeliveryRepository($db), new AuditService($s->pdo), 'del-req');
    }

    private function gateway(ScratchDatabase $s, ?Connection $db = null): OrderOperationsService
    {
        $db ??= $s->connection();
        return new OrderOperationsService($db, new OrderRepository($db), new StockService($db), new PaymentRepository($db),
            new PaymentService($db, new PaymentRepository($db), new OrderRepository($db), new AuditService($s->pdo), 'ops-req'),
            new AuditService($s->pdo), 'ops-req', new DeliveryRepository($db));
    }

    private function deliveryOrder(ScratchDatabase $s, string $number, string $status): int
    {
        $s->pdo->exec("INSERT INTO orders (business_id,branch_id,number,status,public_token,fulfillment,payment_method,customer_name,gross_items_cents,item_promotions_cents,order_promotions_cents,coupon_discount_cents,payment_discount_cents,merchandise_total_cents,delivery_fee_cents,grand_total_cents,delivery_zone_name,delivery_payout_cents) VALUES (1,1,'$number','$status','" . bin2hex(random_bytes(32)) . "','delivery','cash','C',1500,0,0,0,0,1500,300,1800,'Zona Centro',150)");
        $orderId = (int)$s->pdo->lastInsertId();
        (new DeliveryRepository($s->connection()))->createPendingForOrder($orderId);
        return $orderId;
    }

    private function pickupOrder(ScratchDatabase $s): int
    {
        $s->pdo->exec("INSERT INTO orders (business_id,branch_id,number,status,public_token,fulfillment,payment_method,customer_name,gross_items_cents,merchandise_total_cents,delivery_fee_cents,grand_total_cents) VALUES (1,1,'B-000009','pending','" . bin2hex(random_bytes(32)) . "','pickup','cash','C',1500,1500,0,1500)");
        return (int)$s->pdo->lastInsertId();
    }

    private function person(ScratchDatabase $s, int $branchId = 1, string $name = 'Carlitos', int $active = 1): int
    {
        $s->pdo->exec("INSERT INTO delivery_persons (business_id,name,is_active) VALUES (1,'$name',$active)");
        $personId = (int)$s->pdo->lastInsertId();
        $s->pdo->exec("INSERT INTO delivery_person_branches (person_id,branch_id) VALUES ($personId,$branchId)");
        return $personId;
    }

    private function limitedUser(ScratchDatabase $s): int
    {
        $s->pdo->exec("INSERT INTO users (business_id,name,email,password_hash) VALUES (1,'Limited','limited" . bin2hex(random_bytes(3)) . "@del.test','hash')");
        return (int)$s->pdo->lastInsertId();
    }

    private function deliveryId(ScratchDatabase $s, int $orderId): int { return (int)$s->pdo->query("SELECT id FROM deliveries WHERE order_id=$orderId")->fetchColumn(); }
    private function row(ScratchDatabase $s, int $deliveryId): array { return $s->pdo->query('SELECT * FROM deliveries WHERE id=' . $deliveryId)->fetch(PDO::FETCH_ASSOC); }
    private function pin(ScratchDatabase $s, int $deliveryId, int $orderId): string { return Pin::code((new DeliveryRepository($s->connection()))->ensurePinKey(1), $deliveryId, $orderId); }
    private function orderStatus(ScratchDatabase $s, int $orderId): string { return (string)$s->pdo->query('SELECT status FROM orders WHERE id=' . $orderId)->fetchColumn(); }
    private function audits(ScratchDatabase $s, string $action, int $entityId): int { return $this->count($s, "SELECT COUNT(*) FROM audit_log WHERE action='$action' AND entity_id=$entityId"); }
    private function count(ScratchDatabase $s, string $sql): int { return (int)$s->pdo->query($sql)->fetchColumn(); }

    /** DomainException message as a code for typed-error assertions. */
    private function codeOf(callable $fn): string
    {
        try { $fn(); } catch (DomainException $e) { return $e->getMessage(); }
        throw new \RuntimeException('Expected DomainException.');
    }
}
