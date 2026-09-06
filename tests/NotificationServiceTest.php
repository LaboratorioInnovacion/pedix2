<?php declare(strict_types=1);
namespace Tests;

use PDO; use RuntimeException; use VO\Audit\AuditService; use VO\Cart\CartRepository; use VO\Cart\CartService; use VO\Database\Connection; use VO\Database\MigrationRunner; use VO\Delivery\DeliveryRepository; use VO\Delivery\DeliveryService; use VO\Delivery\Pin; use VO\Domain\DbIdempotencyStore; use VO\Inventory\StockService; use VO\Notifications\NotificationRepository; use VO\Notifications\NotificationService; use VO\Notifications\NotificationTransport; use VO\Orders\OrderOperationsService; use VO\Orders\OrderRepository; use VO\Orders\OrderService; use VO\Payments\PaymentRepository; use VO\Payments\PaymentService; use VO\Pricing\PricingRepository; use VO\Pricing\PricingService; use VO\Settings\SettingsRepository;

/**
 * Unit B (specs N1–N4, N7, N8, N10): in-transaction enqueue with flag/recipient
 * gating, the event catalog on real service flows (incl. delivered dedup),
 * dispatch sweep with fake transports (templates + PIN recomputed at send only),
 * typed failures with the attempts cap and per-row isolation, and null-service
 * non-interference. Shares one Connection per flow so enqueues join its transaction.
 */
final class NotificationServiceTest extends TestCase
{
    private const SEED = ['business_name' => 'Notif', 'business_slug' => 'notif', 'branch_name' => 'Main', 'branch_address' => '1 St', 'branch_phone' => '555', 'timezone' => 'America/Argentina/Buenos_Aires', 'admin_name' => 'Owner', 'admin_email' => 'owner@notif.test', 'admin_password' => 'change-me-now'];
    public function testFlagAndRecipientGatingPerChannel(): void
    {
        $s = $this->db(); $db = $s->connection();
        try {
            $orderId = $this->orderRow($s); $order = $this->orderOf($s, $orderId);
            $this->notif($s, $db)->enqueueForOrder('order.created', $order); // no flags at all
            $this->assertSame(0, $this->events($s, 'order.created'), 'absent keys = off');

            $this->flags($s, 'notifications_enabled', 'notifications_email_enabled'); // whatsapp stays off
            $this->notif($s, $db)->enqueueForOrder('order.created', $order); // fresh service: settings cache is per instance
            $this->assertSame(1, $this->events($s, 'order.created', 'email'));
            $this->assertSame(0, $this->events($s, 'order.created', 'whatsapp'), 'channel flag off must not enqueue');
            $row = $this->rows($s, 'order.created', 'email')[0];
            $this->assertSame('ada@example.test', $row['recipient']);
            $this->assertSame('pending', $row['state']);
            $this->assertSame(null, $row['subject'], 'templates render at dispatch, not at enqueue');
            $this->assertSame(['order_id' => $orderId, 'order_number' => 'B-000001'], json_decode((string)$row['context_json'], true), 'context carries ids only');

            $this->flags($s, 'notifications_enabled', 'notifications_whatsapp_enabled');
            $this->notif($s, $db)->enqueueForOrder('order.created', $order); // phone present → whatsapp row too
            $this->assertSame(1, $this->events($s, 'order.created', 'whatsapp'));
            $this->assertSame('+549115555000', $this->rows($s, 'order.created', 'whatsapp')[0]['recipient']);
            $noPhone = $this->orderOf($s, $this->orderRow($s, 'B-000002', email: '', phone: ''));
            $this->notif($s, $db)->enqueueForOrder('order.created', $noPhone);
            $this->assertSame(1, $this->events($s, 'order.created', 'whatsapp'), 'empty recipient must not enqueue');
        } finally { $s->drop(); }
    }

    public function testEnqueueJoinsCallerTransactionAndRollbackRemovesRows(): void
    {
        $s = $this->db(); $db = $s->connection(); $svc = $this->notif($s, $db);
        $this->flags($s, 'notifications_enabled', 'notifications_email_enabled');
        try {
            $order = $this->orderOf($s, $this->orderRow($s));
            try {
                $db->transaction(function () use ($svc, $order): void {
                    $svc->enqueueForOrder('order.accepted', $order);
                    throw new RuntimeException('side effect failed');
                });
            } catch (RuntimeException) { /* expected */ }
            $this->assertSame(0, $this->events($s, 'order.accepted'), 'rollback must remove the outbox row (spec N1)');
            $db->transaction(function () use ($svc, $order): void { $svc->enqueueForOrder('order.accepted', $order); });
            $this->assertSame(1, $this->events($s, 'order.accepted'));
        } finally { $s->drop(); }
    }

    public function testRealFlowHooksEnqueueCatalogEvents(): void
    {
        $s = $this->db(); $db = $s->connection();
        $this->flags($s, 'notifications_enabled', 'notifications_email_enabled', 'notifications_whatsapp_enabled');
        $n = $this->notif($s, $db); $pricing = new PricingService(new PricingRepository($s->pdo));
        $orders = new OrderService($db, new OrderRepository($db), new DbIdempotencyStore($db), $pricing, null, $n);
        $payments = $this->payments($s, $db, $n);
        try {
            $ids = $this->seedCatalog($s);
            $cart = new CartService(new CartRepository($s->pdo), $pricing, new DeliveryRepository($db));
            $hash = hash('sha256', 'cart-notif');
            $cart->addToCart($hash, $ids['item'], $ids['variant'], 2, []);
            $cart->setCheckoutData($hash, ['fulfillment' => 'pickup', 'branch_id' => 1, 'payment_method' => 'transfer']);
            $order = $orders->createFromCart($hash, 'idem-n1', $this->accepted($pricing, $s, $hash), ['name' => 'Ada', 'email' => 'ada@example.test', 'phone' => '+549115555000']);
            $this->assertSame(1, $this->events($s, 'order.created', 'email'), 'order.created fired inside createFromCart');

            $payment = $payments->initiateForOrder((int)$order['id'], 'transfer');
            $payments->verifyTransfer((int)$payment['id'], 1);
            // Decision 9: auto-accept enqueues order.accepted AND payment.verified stays its own event.
            $this->assertSame(1, $this->events($s, 'payment.verified', 'email'));
            $this->assertSame(1, $this->events($s, 'order.accepted', 'email'));

            $rejected = $this->orderOf($s, $this->orderRow($s, 'B-000002', method: 'transfer'));
            $pay2 = $payments->initiateForOrder((int)$rejected['id'], 'transfer');
            $payments->rejectPayment((int)$pay2['id'], 1, 'sin comprobante');
            $this->assertSame(1, $this->events($s, 'payment.rejected', 'email'));
            $this->assertSame(0, $this->events($s, 'payment.cancelled'), 'cancelled never enqueues');
        } finally { $s->drop(); }
    }

    public function testDeliveryLifecycleHooksAndCompletedDedup(): void
    {
        $s = $this->db(); $db = $s->connection();
        $this->flags($s, 'notifications_enabled', 'notifications_email_enabled');
        $n = $this->notif($s, $db);
        $delivery = $this->delivery($s, $db, $n); $gw = $this->gateway($s, $db, $n);
        try {
            $orderId = $this->deliveryOrder($s, 'B-000001', 'accepted');
            $deliveryId = $this->deliveryId($s, $orderId); $personId = $this->person($s);
            $delivery->assign($deliveryId, $personId, 1);
            $ctx = json_decode((string)$this->rows($s, 'delivery.assigned', 'email')[0]['context_json'], true);
            $this->assertSame(['order_id' => $orderId, 'order_number' => 'B-000001', 'delivery_id' => $deliveryId], $ctx, 'assigned carries delivery id');
            $delivery->assign($deliveryId, $this->person($s, 1, 'Otro'), 1);
            $this->assertSame(2, $this->events($s, 'delivery.assigned'), 'reassign re-enqueues (design D8)');
            $delivery->markPickedUp($deliveryId, 1);
            $this->assertSame(1, $this->events($s, 'delivery.picked_up'));
            $gw->transition($orderId, 'in_progress', 1); $gw->transition($orderId, 'ready', 1);
            $pin = Pin::code((new DeliveryRepository($db))->ensurePinKey(1), $deliveryId, $orderId);
            $delivery->deliver($deliveryId, $pin, 1);
            $this->assertSame(1, $this->events($s, 'delivery.delivered'));
            $this->assertSame(0, $this->events($s, 'order.completed'), 'delivery orders dedup: delivered is notified once');

            // Pickup fulfillment notifies order.completed; change approval keeps its own event.
            $pickup = $this->orderRow($s, 'B-000003', fulfillment: 'pickup');
            $s->pdo->exec("UPDATE orders SET status='ready' WHERE id=$pickup");
            $gw->transition($pickup, 'completed', 1);
            $this->assertSame(1, $this->events($s, 'order.completed'));
            $change = $this->orderRow($s, 'B-000004');
            $s->pdo->exec("UPDATE orders SET status='change_proposed' WHERE id=$change");
            $gw->transition($change, 'accepted', 1);
            $this->assertSame(1, $this->events($s, 'order.change_approved'));
            $this->assertSame(0, $this->events($s, 'order.accepted'), 'change approval must not emit order.accepted');
        } finally { $s->drop(); }
    }

    public function testSweepSendsTemplatesAndRecomputesPinAtSendOnly(): void
    {
        $s = $this->db(); $db = $s->connection();
        $this->flags($s, 'notifications_enabled', 'notifications_email_enabled', 'notifications_whatsapp_enabled');
        $smtp = new FakeTransport(); $wa = new FakeTransport();
        $svc = $this->notif($s, $db, $smtp, $wa);
        try {
            $orderId = $this->orderRow($s); $order = $this->orderOf($s, $orderId);
            $svc->enqueueForOrder('order.ready', $order);
            $this->assertSame(2, $svc->dispatchPendingLazy(), 'sweep returns the dispatched count');
            $this->assertSame('ada@example.test', $smtp->calls[0]['to']);
            $this->assertTrue(str_contains($smtp->calls[0]['subject'], 'B-000001'), 'subject renders the order number');
            $this->assertTrue(str_contains($smtp->calls[0]['body'], 'B-000001') && str_contains($smtp->calls[0]['body'], 'listo'), 'body renders number + Spanish status label');
            $sentRow = $this->rows($s, 'order.ready', 'email')[0];
            $this->assertSame('sent', $sentRow['state']);
            $this->assertNotSame(null, $sentRow['sent_at'], 'sent rows carry sent_at');

            // PIN only in the WhatsApp delivery.assigned body, recomputed at send (spec N7).
            $dOrder = $this->deliveryOrder($s, 'B-000002', 'accepted');
            $deliveryId = $this->deliveryId($s, $dOrder);
            $svc->enqueueForOrder('delivery.assigned', $this->orderOf($s, $dOrder), $deliveryId);
            $pin = Pin::code((new DeliveryRepository($db))->ensurePinKey(1), $deliveryId, $dOrder);
            $stored = $this->rows($s, 'delivery.assigned', 'whatsapp')[0];
            $this->assertTrue(!str_contains((string)$stored['context_json'], $pin), 'PIN must never be stored at rest');
            $this->assertSame(2, $svc->dispatchPendingLazy(), 'one email + one whatsapp pending row');
            $waCall = end($wa->calls);
            $this->assertTrue(str_contains($waCall['body'], 'PIN de entrega: ' . $pin), 'whatsapp body carries the recomputed PIN');
            $this->assertTrue(str_contains($waCall['body'], 'B-000002'), 'whatsapp body renders the order number');
            foreach ($smtp->calls as $call) $this->assertTrue(!str_contains($call['body'], $pin), 'email bodies never contain the PIN');
        } finally { $s->drop(); }
    }

    public function testSweepFailureTypingAttemptsCapAndRowIsolation(): void
    {
        $s = $this->db(); $db = $s->connection();
        $this->flags($s, 'notifications_enabled', 'notifications_email_enabled', 'notifications_whatsapp_enabled');
        $failing = new FakeTransport(['ok' => false, 'error' => 'smtp: mailbox unavailable']);
        $svc = $this->notif($s, $db, $failing, $failing);
        try {
            $order = $this->orderOf($s, $this->orderRow($s));
            $svc->enqueueForOrder('order.created', $order); // email + whatsapp rows
            $this->assertSame(0, $svc->dispatchPendingLazy(), 'failed sends are not counted as dispatched');
            foreach ($this->allRows($s) as $row) {
                $this->assertSame('pending', $row['state']);
                $this->assertSame(1, (int)$row['attempts'], 'first failure records attempt 1');
                $this->assertSame('smtp: mailbox unavailable', $row['last_error']);
            }
            $svc->dispatchPendingLazy(); $svc->dispatchPendingLazy();
            foreach ($this->allRows($s) as $row) {
                $this->assertSame('failed', $row['state'], 'cap 3 converts the row to failed');
                $this->assertSame(3, (int)$row['attempts']);
            }
            $this->assertSame([], (new NotificationRepository($db))->pending(10, 3), 'capped rows are never selected again');

            // Per-row isolation: a throwing transport must not stop the sweep (spec N10).
            $throwing = new FakeTransport(null, new RuntimeException('socket exploded'));
            $good = new FakeTransport();
            $mixed = $this->notif($s, $db, $throwing, $good);
            $mixed->enqueueForOrder('order.accepted', $order); // email → throwing, whatsapp → good
            $this->assertSame(1, $mixed->dispatchPendingLazy(), 'the healthy row still dispatches');
            $bad = $this->rows($s, 'order.accepted', 'email')[0];
            $this->assertSame('pending', $bad['state'], 'one failure keeps the row pending (spec N4)');
            $this->assertSame(1, (int)$bad['attempts']);
            $this->assertTrue(str_contains((string)$bad['last_error'], 'socket exploded'), 'typed error stored, exception contained');

            // Unconfigured transports produce the typed per-attempt failure (spec N4).
            $none = $this->notif($s, $db);
            $none->enqueueForOrder('order.ready', $order);
            $none->dispatchPendingLazy();
            $row = $this->rows($s, 'order.ready', 'email')[0];
            $this->assertSame('canal no configurado', $row['last_error']);
            $this->assertSame(1, (int)$row['attempts']);
        } finally { $s->drop(); }
    }

    public function testNullNotificationServiceLeavesFlowsUnchanged(): void
    {
        $s = $this->db(); $db = $s->connection();
        $this->flags($s, 'notifications_enabled', 'notifications_email_enabled', 'notifications_whatsapp_enabled');
        $delivery = $this->delivery($s, $db); $gw = $this->gateway($s, $db); $payments = $this->payments($s, $db);
        try {
            $orderId = $this->deliveryOrder($s, 'B-000001', 'accepted');
            $deliveryId = $this->deliveryId($s, $orderId);
            $delivery->assign($deliveryId, $this->person($s), 1);
            $delivery->markPickedUp($deliveryId, 1);
            $gw->transition($orderId, 'in_progress', 1); $gw->transition($orderId, 'ready', 1);
            $pin = Pin::code((new DeliveryRepository($db))->ensurePinKey(1), $deliveryId, $orderId);
            $delivery->deliver($deliveryId, $pin, 1);
            $this->assertSame('completed', (string)$s->pdo->query('SELECT status FROM orders WHERE id=' . $orderId)->fetchColumn(), 'flow must still complete the order');
            $payment = $payments->initiateForOrder($this->orderRow($s, 'B-000002', fulfillment: 'pickup', method: 'transfer'), 'transfer');
            $payments->verifyTransfer((int)$payment['id'], 1);
            $this->assertSame('accepted', (string)$s->pdo->query('SELECT status FROM orders WHERE id=' . (int)$payment['order_id'])->fetchColumn(), 'payment flow must still auto-accept');
            $this->assertSame(0, (int)$s->pdo->query('SELECT COUNT(*) FROM notification_events')->fetchColumn(), 'no service injected → no rows, flows intact');
        } finally { $s->drop(); }
    }

    // ---- helpers ----

    private function db(): ScratchDatabase
    {
        $s = ScratchDatabase::create('vo_notif11_test_');
        (new MigrationRunner($s->connection(), dirname(__DIR__) . '/api/database/migrations'))->run();
        (new \VO\Installer\InstallerSeeder($s->connection()))->seed(self::SEED); // owner user id 1 carries the operator permissions
        return $s;
    }

    /** One shared Connection instance across flows + notification service: enqueues join the same transaction. */
    private function notif(ScratchDatabase $s, Connection $db, ?NotificationTransport $smtp = null, ?NotificationTransport $wa = null): NotificationService
    {
        return new NotificationService($db, new NotificationRepository($db), new SettingsRepository($s->pdo), $smtp, $wa);
    }

    private function gateway(ScratchDatabase $s, Connection $db, ?NotificationService $n = null): OrderOperationsService
    {
        return new OrderOperationsService($db, new OrderRepository($db), new StockService($db), new PaymentRepository($db),
            new PaymentService($db, new PaymentRepository($db), new OrderRepository($db), new AuditService($s->pdo), 'notif-req', null, null, $n),
            new AuditService($s->pdo), 'notif-req', new DeliveryRepository($db), $n);
    }

    private function delivery(ScratchDatabase $s, Connection $db, ?NotificationService $n = null): DeliveryService
    {
        return new DeliveryService($db, new DeliveryRepository($db), new AuditService($s->pdo), 'notif-req', $n);
    }

    private function payments(ScratchDatabase $s, Connection $db, ?NotificationService $n = null): PaymentService
    {
        return new PaymentService($db, new PaymentRepository($db), new OrderRepository($db), new AuditService($s->pdo), 'notif-req', null, null, $n);
    }

    private function flags(ScratchDatabase $s, string ...$on): void
    {
        foreach (['notifications_enabled', 'notifications_email_enabled', 'notifications_whatsapp_enabled'] as $key) {
            $s->pdo->exec("INSERT INTO business_settings (business_id,setting_key,setting_value) VALUES (1,'$key','" . (in_array($key, $on, true) ? '1' : '0') . "') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        }
    }

    private function orderRow(ScratchDatabase $s, string $number = 'B-000001', string $email = 'ada@example.test', string $phone = '+549115555000', string $fulfillment = 'pickup', string $method = 'cash'): int
    {
        $s->pdo->exec("INSERT INTO orders (business_id,branch_id,number,status,public_token,fulfillment,payment_method,customer_name,customer_email,customer_phone,gross_items_cents,merchandise_total_cents,delivery_fee_cents,grand_total_cents) VALUES (1,1,'$number','pending','" . bin2hex(random_bytes(32)) . "','$fulfillment','$method','Ada','$email','$phone',1500,1500,0,1500)");
        return (int)$s->pdo->lastInsertId();
    }

    private function deliveryOrder(ScratchDatabase $s, string $number, string $status, string $email = 'ada@example.test'): int
    {
        $orderId = $this->orderRow($s, $number, $email, fulfillment: 'delivery');
        $s->pdo->exec("UPDATE orders SET status='$status' WHERE id=$orderId");
        (new DeliveryRepository($s->connection()))->createPendingForOrder($orderId);
        return $orderId;
    }

    private function seedCatalog(ScratchDatabase $s): array
    {
        $s->pdo->exec("INSERT INTO branch_settings (branch_id,setting_key,setting_value) VALUES (1,'minimum_pickup_cents','0'),(1,'minimum_delivery_cents','0')");
        $s->pdo->exec("INSERT INTO categories (id,name,slug) VALUES (1,'Food','food')");
        $s->pdo->exec("INSERT INTO catalog_items (category_id,type,name,slug,base_price_cents,requires_variant,allows_delivery,is_active) VALUES (1,'product','Burger','burger',1000,0,1,1)");
        $item = (int)$s->pdo->lastInsertId();
        $s->pdo->exec("INSERT INTO item_variants (item_id,name,price_cents,is_active) VALUES ($item,'Default',1000,1)");
        $variant = (int)$s->pdo->lastInsertId();
        $s->pdo->exec("INSERT INTO branch_items (branch_id,item_id,is_available,stock_quantity) VALUES (1,$item,1,10)");
        $s->pdo->exec("INSERT INTO branch_variants (branch_id,variant_id,is_available) VALUES (1,$variant,1)");
        return ['item' => $item, 'variant' => $variant];
    }

    private function accepted(PricingService $pricing, ScratchDatabase $s, string $hash): array
    {
        $c = $s->pdo->query('SELECT * FROM carts WHERE token_hash=' . $s->pdo->quote($hash))->fetch(PDO::FETCH_ASSOC);
        $rows = $s->pdo->query('SELECT ci.*,NULL mods FROM cart_items ci WHERE ci.cart_id=' . (int)$c['id'] . ' ORDER BY ci.id')->fetchAll(PDO::FETCH_ASSOC);
        $q = $pricing->quote(['branch_id' => (int)$c['branch_id'], 'fulfillment' => $c['fulfillment'], 'coupon_code' => $c['coupon_code'] ?? '', 'payment_method' => $c['payment_method'] ?? '', 'delivery_fee_cents' => $c['fulfillment'] === 'delivery' ? (int)$c['delivery_fee_cents'] : 0,
            'items' => array_map(fn($r) => ['item_id' => (int)$r['item_id'], 'variant_id' => $r['variant_id'] === null ? null : (int)$r['variant_id'], 'quantity' => (int)$r['qty'], 'modifier_ids' => []], $rows)]);
        return $q + ['accepted_grand_total_cents' => $q['grand_total_cents'], 'accepted_lines' => array_map(fn($l) => ['line_total_cents' => $l['line_total_cents']], $q['lines'])];
    }

    private function person(ScratchDatabase $s, int $branchId = 1, string $name = 'Carlitos'): int
    {
        $s->pdo->exec("INSERT INTO delivery_persons (business_id,name,is_active) VALUES (1,'$name',1)");
        $personId = (int)$s->pdo->lastInsertId();
        $s->pdo->exec("INSERT INTO delivery_person_branches (person_id,branch_id) VALUES ($personId,$branchId)");
        return $personId;
    }

    private function orderOf(ScratchDatabase $s, int $id): array { return $s->pdo->query('SELECT * FROM orders WHERE id=' . $id)->fetch(PDO::FETCH_ASSOC); }
    private function deliveryId(ScratchDatabase $s, int $orderId): int { return (int)$s->pdo->query('SELECT id FROM deliveries WHERE order_id=' . $orderId)->fetchColumn(); }
    private function events(ScratchDatabase $s, string $event, ?string $channel = null): int
    {
        $sql = "SELECT COUNT(*) FROM notification_events WHERE event='$event'" . ($channel !== null ? " AND channel='$channel'" : '');
        return (int)$s->pdo->query($sql)->fetchColumn();
    }
    /** @return array<int,array<string,mixed>> */
    private function rows(ScratchDatabase $s, string $event, string $channel): array
    {
        return $s->pdo->query("SELECT * FROM notification_events WHERE event='$event' AND channel='$channel' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }
    /** @return array<int,array<string,mixed>> */
    private function allRows(ScratchDatabase $s): array { return $s->pdo->query('SELECT * FROM notification_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC); }
}

/** Capturing fake transport: scripted typed outcome, optionally throwing to prove sweep isolation. */
final class FakeTransport implements NotificationTransport
{
    public array $calls = [];
    public function __construct(private ?array $response = null, private ?\Throwable $throw = null) {}
    public function send(string $to, string $subject, string $body): array
    {
        $this->calls[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
        if ($this->throw !== null) throw $this->throw;
        return $this->response ?? ['ok' => true, 'error' => null];
    }
}
