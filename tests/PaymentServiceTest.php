<?php declare(strict_types=1);
namespace Tests;

use DomainException; use PDO; use VO\Audit\AuditService; use VO\Database\MigrationRunner; use VO\Domain\InvalidTransition; use VO\Orders\OrderRepository; use VO\Payments\PaymentRepository; use VO\Payments\PaymentService; use VO\Payments\PaymentStateMap;

final class PaymentServiceTest extends TestCase
{
    public function testTransferVerificationAcceptsPendingOrderAndAudits(): void
    {
        $s = $this->db();
        try {
            $svc = $this->service($s); $orderId = $this->order($s, 'transfer');
            $payment = $svc->initiateForOrder($orderId, 'transfer');
            $this->assertSame('pending_verification', $payment['state']); $this->assertSame(1500, (int)$payment['amount_cents']);
            $verified = $svc->verifyTransfer((int)$payment['id'], 1);
            $this->assertSame('verified', $verified['state']); $this->assertSame(1, (int)$verified['verified_by']); $this->assertTrue($verified['verified_at'] !== null, 'verified_at missing');
            $this->assertSame('accepted', (string)$s->pdo->query('SELECT status FROM orders WHERE id=' . $orderId)->fetchColumn());
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='payment.verified' AND request_id='pay-req'")->fetchColumn());
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM payment_events WHERE event_type='payment.verified'")->fetchColumn());
        } finally { $s->drop(); }
    }

    public function testRejectKeepsOrderPendingAndCashInitiationIsRejected(): void
    {
        $s = $this->db();
        try {
            $svc = $this->service($s); $transfer = $this->order($s, 'transfer'); $cash = $this->order($s, 'cash', 'B-000002');
            $payment = $svc->initiateForOrder($transfer, 'transfer');
            $rejected = $svc->rejectPayment((int)$payment['id'], 1, 'No proof');
            $this->assertSame('rejected', $rejected['state']);
            $this->assertSame('pending', (string)$s->pdo->query('SELECT status FROM orders WHERE id=' . $transfer)->fetchColumn());
            $this->assertThrows(DomainException::class, fn() => $svc->initiateForOrder($cash, 'cash'));
            $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
        } finally { $s->drop(); }
    }

    public function testInvalidTransitionAndStateMapRejectIllegalMoves(): void
    {
        $s = $this->db();
        try {
            $svc = $this->service($s); $payment = $svc->initiateForOrder($this->order($s, 'transfer'), 'transfer');
            $svc->verifyTransfer((int)$payment['id'], 1);
            $this->assertThrows(InvalidTransition::class, fn() => $svc->rejectPayment((int)$payment['id'], 1, 'late'));
            $this->assertSame('verified', (string)$s->pdo->query('SELECT state FROM payments WHERE id=' . (int)$payment['id'])->fetchColumn());
            $this->assertTrue(PaymentStateMap::create()->can('refund_pending', 'refund_completed'));
        } finally { $s->drop(); }
    }

    public function testExpiryLazySweepCancelsStaleAndLeavesFresh(): void
    {
        $s = $this->db();
        try {
            $svc = $this->service($s); $stale = $svc->initiateForOrder($this->order($s, 'transfer'), 'transfer'); $fresh = $svc->initiateForOrder($this->order($s, 'mercadopago', 'B-000002'), 'mercadopago');
            $s->pdo->exec("UPDATE payments SET expires_at=DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id=" . (int)$stale['id']);
            $this->assertSame(1, $svc->expireStaleLazy());
            $this->assertSame('cancelled', (string)$s->pdo->query('SELECT state FROM payments WHERE id=' . (int)$stale['id'])->fetchColumn());
            $this->assertSame('pending', (string)$s->pdo->query('SELECT state FROM payments WHERE id=' . (int)$fresh['id'])->fetchColumn());
            $this->assertSame(2, (int)$s->pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn());
        } finally { $s->drop(); }
    }

    public function testAutoAcceptIsIdempotentWhenOrderAlreadyAccepted(): void
    {
        $s = $this->db();
        try {
            $svc = $this->service($s); $orderId = $this->order($s, 'transfer'); (new OrderRepository($s->connection()))->markStatus($orderId, 'accepted');
            $payment = $svc->initiateForOrder($orderId, 'transfer'); $svc->verifyTransfer((int)$payment['id'], 1); $again = $svc->autoAcceptOrder($orderId);
            $this->assertSame('accepted', $again['status']);
        } finally { $s->drop(); }
    }

    private function db(): ScratchDatabase
    {
        $s = ScratchDatabase::create('vo_pay8_test_'); (new MigrationRunner($s->connection(), dirname(__DIR__) . '/api/database/migrations'))->run();
        $s->pdo->exec("INSERT INTO businesses (id,name,slug) VALUES (1,'Demo','demo')");
        $s->pdo->exec("INSERT INTO users (id,business_id,name,email,password_hash) VALUES (1,1,'Admin','admin@example.test','hash')");
        $s->pdo->exec("INSERT INTO branches (id,business_id,name,is_active) VALUES (1,1,'Main',1)");
        return $s;
    }

    private function service(ScratchDatabase $s): PaymentService { $db = $s->connection(); return new PaymentService($db, new PaymentRepository($db), new OrderRepository($db), new AuditService($s->pdo), 'pay-req'); }
    private function order(ScratchDatabase $s, string $method, string $number = 'B-000001'): int { $s->pdo->exec("INSERT INTO orders (business_id,branch_id,number,public_token,fulfillment,payment_method,gross_items_cents,item_promotions_cents,order_promotions_cents,coupon_discount_cents,payment_discount_cents,merchandise_total_cents,delivery_fee_cents,grand_total_cents) VALUES (1,1," . $s->pdo->quote($number) . ",'" . bin2hex(random_bytes(32)) . "','pickup'," . $s->pdo->quote($method) . ",1500,0,0,0,0,1500,0,1500)"); return (int)$s->pdo->lastInsertId(); }
}
