<?php declare(strict_types=1);
namespace VO\Orders;

use DomainException; use InvalidArgumentException; use VO\Audit\AuditService; use VO\Database\Connection; use VO\Delivery\DeliveryRepository; use VO\Domain\InvalidTransition; use VO\Inventory\StockService; use VO\Notifications\NotificationService; use VO\Payments\PaymentRepository; use VO\Payments\PaymentService;

/**
 * Single gateway for operator-driven order status changes (operations capability).
 * One transaction per public operation; never nested (PdoConnection throws on nesting),
 * so payment mutations inside an active transition use PaymentService's within-transaction path,
 * and delivery mutations use DeliveryRepository's within-transaction methods.
 */
final class OrderOperationsService
{
    /** target status => seeded permission key. No orders.complete exists: completed reuses orders.prepare (documented assumption). */
    private const PERMISSIONS = [
        'accepted' => 'orders.accept',
        'rejected' => 'orders.reject',
        'in_progress' => 'orders.prepare',
        'ready' => 'orders.mark_ready',
        'completed' => 'orders.prepare',
        'cancelled' => 'orders.cancel',
    ];
    private const EXPIRY_DEFAULT_HOURS = 24;

    private ?AuditService $audit;
    private ?string $requestId;

    public function __construct(private Connection $db, private OrderRepository $orders, private StockService $stock,
        private PaymentRepository $payments, private PaymentService $paymentsSvc, ?AuditService $audit = null, ?string $requestId = null,
        private ?DeliveryRepository $deliveries = null, private ?NotificationService $notifications = null)
    {
        $this->audit = $audit; $this->requestId = $requestId;
    }

    /** @throws InvalidTransition|InvalidArgumentException|DomainException */
    public function transition(int $orderId, string $to, int $operatorUserId, ?string $reason = null): array
    {
        $permission = self::PERMISSIONS[$to] ?? throw new InvalidArgumentException('Unknown order transition target.');
        if (!$this->hasPermission($operatorUserId, $permission)) {
            $this->audit?->append(['type' => 'user', 'id' => $operatorUserId], 'authz.denied', 'order:' . $orderId,
                ['required' => $permission, 'to' => $to], $this->requestId);
            throw new DomainException('PERMISSION_DENIED');
        }
        if ($to === 'cancelled' && ($reason === null || trim($reason) === '')) throw new InvalidArgumentException('CANCEL_REASON_REQUIRED');
        return $this->db->transaction(fn(): array => $this->transitionWithinTransaction($orderId, $to, ['type' => 'user', 'id' => $operatorUserId], $reason));
    }

    /** Payment-driven auto-accept: system actor, no permission check, no wrapping transaction (caller holds one). Idempotent. */
    public function acceptFromPayment(int $orderId): array
    {
        $order = $this->orders->lockById($orderId);
        if (!$order) throw new DomainException('ORDER_NOT_FOUND');
        if ((string)$order['status'] !== 'pending') return $order;
        return $this->transitionWithinTransaction($orderId, 'accepted', ['type' => 'system'], null);
    }

    /** Delivery-driven auto-complete (spec D7): system actor, no permission check, no wrapping transaction (caller holds one). No-op unless ready. */
    public function completeFromDelivery(int $orderId): array
    {
        $order = $this->orders->lockById($orderId);
        if (!$order) throw new DomainException('ORDER_NOT_FOUND');
        if ((string)$order['status'] !== 'ready') return $order;
        return $this->transitionWithinTransaction($orderId, 'completed', ['type' => 'system'], null);
    }

    /** Expires pending/change_proposed orders older than the business TTL; returns how many expired. */
    public function expireStaleLazy(): int
    {
        $count = 0;
        $businessIds = array_map(static fn(array $r): int => (int)$r['business_id'],
            $this->db->select("SELECT DISTINCT business_id FROM orders WHERE status IN ('pending','change_proposed')"));
        foreach ($businessIds as $businessId) {
            foreach ($this->orders->findStaleForExpiry($this->expiryHours($businessId), $businessId) as $stale) {
                $expired = $this->db->transaction(function () use ($stale): bool {
                    $order = $this->orders->lockById((int)$stale['id']);
                    if (!$order || !in_array((string)$order['status'], ['pending', 'change_proposed'], true)) return false;
                    $orderId = (int)$order['id'];
                    $this->orders->markStatus($orderId, 'expired');
                    $this->stock->release((int)$order['business_id'], (int)$order['branch_id'], $orderId, $this->orderItems($orderId), 'release_expired');
                    $payment = $this->payments->getByOrderId($orderId);
                    if ($payment && in_array((string)$payment['state'], ['pending', 'pending_verification'], true)) {
                        $this->paymentsSvc->cancelPaymentWithinTransaction((int)$payment['id'], null, 'expired');
                    }
                    $this->audit?->append(['type' => 'system'], 'orders.expired', 'order:' . $orderId,
                        ['from' => (string)$order['status'], 'to' => 'expired'], $this->requestId);
                    return true;
                });
                if ($expired) $count++;
            }
        }
        return $count;
    }

    private function transitionWithinTransaction(int $orderId, string $to, array $actor, ?string $reason): array
    {
        $order = $this->orders->lockById($orderId);
        if (!$order) throw new DomainException('ORDER_NOT_FOUND');
        $from = (string)$order['status'];
        OrderStateMap::create()->transition($from, $to); // legality check on the locked row: throws InvalidTransition before any side effect
        $this->orders->markStatus($orderId, $to);
        $meta = ['from' => $from, 'to' => $to];
        if ($reason !== null && trim($reason) !== '') $meta['reason'] = trim($reason);
        $items = null;
        switch ($to) {
            case 'accepted':
                $this->stock->consume((int)$order['business_id'], (int)$order['branch_id'], $orderId, $this->orderItems($orderId));
                break;
            case 'rejected':
                $this->stock->release((int)$order['business_id'], (int)$order['branch_id'], $orderId, $this->orderItems($orderId), 'release_rejected');
                break;
            case 'cancelled':
                $this->stock->release((int)$order['business_id'], (int)$order['branch_id'], $orderId, $this->orderItems($orderId), 'release_cancelled');
                $payment = $this->payments->getByOrderId($orderId);
                if ($payment) {
                    $state = (string)$payment['state'];
                    if (in_array($state, ['pending', 'pending_verification'], true)) {
                        $this->paymentsSvc->cancelPaymentWithinTransaction((int)$payment['id'], $actor['id'] ?? null, (string)($meta['reason'] ?? 'cancelled'));
                    } elseif ($state === 'approved') {
                        $meta['refund_required'] = true; // approved money stays approved; refund handled out of band
                    }
                }
                $cascade = $this->deliveries?->cancelActiveForOrder($orderId); // spec D8: active delivery dies with the order, same transaction, no extra permission
                if ($cascade !== null) {
                    $this->audit?->append($actor, 'deliveries.cancelled', 'delivery:' . $cascade['id'],
                        ['from' => $cascade['from'], 'to' => 'cancelled', 'cascade' => 'order_cancelled', 'order_id' => $orderId], $this->requestId);
                    $meta['delivery_cancelled'] = true;
                }
                break;
        }
        $this->audit?->append($actor, 'orders.' . $to, 'order:' . $orderId, $meta, $this->requestId);
        $event = $this->notificationEvent($from, $to, (string)$order['fulfillment']);
        if ($event !== null) $this->notifications?->enqueueForOrder($event, $order); // outbox row joins this transaction
        return $this->orders->getById($orderId) ?? [];
    }

    /** Spec N2 catalog: change approval keeps its own event; pickup-only completed (delivery orders notify via delivery.delivered); expired never enqueues. */
    private function notificationEvent(string $from, string $to, string $fulfillment): ?string
    {
        return match ($to) {
            'accepted' => $from === 'change_proposed' ? 'order.change_approved' : 'order.accepted',
            'rejected' => 'order.rejected',
            'in_progress' => 'order.in_progress',
            'ready' => 'order.ready',
            'cancelled' => 'order.cancelled',
            'completed' => $fulfillment === 'pickup' ? 'order.completed' : null,
            default => null,
        };
    }

    /** Same DISTINCT-permission SQL PermissionGuard runs, but through the Connection (no PDO dependency here). */
    private function hasPermission(int $userId, string $key): bool
    {
        $rows = $this->db->select('SELECT DISTINCT p.permission_key FROM user_roles ur INNER JOIN role_permissions rp ON rp.role_id=ur.role_id INNER JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=?', [$userId]);
        foreach ($rows as $row) if ((string)$row['permission_key'] === $key) return true;
        return false;
    }

    private function expiryHours(int $businessId): int
    {
        $row = $this->db->select("SELECT setting_value FROM business_settings WHERE business_id=? AND setting_key='orders.order_expiry_hours' LIMIT 1", [$businessId]);
        $hours = (int)($row[0]['setting_value'] ?? 0);
        return $hours > 0 ? $hours : self::EXPIRY_DEFAULT_HOURS;
    }

    private function orderItems(int $orderId): array { return $this->db->select('SELECT * FROM order_items WHERE order_id=? ORDER BY id', [$orderId]); }
}
