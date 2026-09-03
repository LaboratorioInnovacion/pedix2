<?php declare(strict_types=1);
namespace VO\Delivery;

use DomainException; use InvalidArgumentException; use VO\Audit\AuditService; use VO\Database\Connection; use VO\Inventory\StockService; use VO\Notifications\NotificationService; use VO\Orders\OrderOperationsService; use VO\Orders\OrderRepository; use VO\Payments\PaymentRepository; use VO\Payments\PaymentService;

/**
 * Delivery lifecycle service (spec D5–D8). One transaction per public operation;
 * never nested — order completion inside deliver() goes through
 * OrderOperationsService::completeFromDelivery(), which expects a caller-held
 * transaction (mirrors acceptFromPayment).
 *
 * Typed errors (DomainException codes): PERMISSION_DENIED, DELIVERY_NOT_FOUND,
 * ORDER_NOT_FOUND, ORDER_NOT_ASSIGNABLE, PERSON_NOT_AVAILABLE, ORDER_NOT_READY,
 * PIN_INVALID, PIN_EXHAUSTED. Invalid transitions throw VO\Domain\InvalidTransition.
 * A missing fail() reason throws InvalidArgumentException('FAIL_REASON_REQUIRED').
 */
final class DeliveryService
{
    private const MAX_PIN_ATTEMPTS = 5;
    /** Assignment window per design: orders from accepted onward, while the order is still active. */
    private const ASSIGNABLE_ORDER_STATUSES = ['accepted', 'in_progress', 'ready'];

    private ?AuditService $audit;
    private ?string $requestId;

    public function __construct(private Connection $db, private DeliveryRepository $repo, ?AuditService $audit = null, ?string $requestId = null, private ?NotificationService $notifications = null)
    {
        $this->audit = $audit; $this->requestId = $requestId;
    }

    /** pending → assigned. Requires deliveries.assign. */
    public function assign(int $deliveryId, int $personId, int $operatorUserId): array
    {
        $this->assertPermission($operatorUserId, 'deliveries.assign', $deliveryId);
        return $this->db->transaction(fn(): array => $this->assignWithinTransaction($deliveryId, $personId, $operatorUserId, 'deliveries.assigned'));
    }

    /** assigned → assigned (reassign). Requires deliveries.reassign; blocked once picked_up or beyond (state map). */
    public function reassign(int $deliveryId, int $personId, int $operatorUserId): array
    {
        $this->assertPermission($operatorUserId, 'deliveries.reassign', $deliveryId);
        return $this->db->transaction(fn(): array => $this->assignWithinTransaction($deliveryId, $personId, $operatorUserId, 'deliveries.reassigned'));
    }

    /** assigned → picked_up. Requires deliveries.assign. */
    public function markPickedUp(int $deliveryId, int $operatorUserId): array
    {
        $this->assertPermission($operatorUserId, 'deliveries.assign', $deliveryId);
        return $this->db->transaction(function () use ($deliveryId, $operatorUserId): array {
            $delivery = $this->repo->findById($deliveryId) ?? throw new DomainException('DELIVERY_NOT_FOUND');
            $order = $this->orderById((int)$delivery['order_id']) ?? throw new DomainException('ORDER_NOT_FOUND');
            $this->assertBranchScope($operatorUserId, (int)$order['branch_id'], $deliveryId, 'deliveries.assign');
            $from = (string)$delivery['state'];
            DeliveryStateMap::create()->transition($from, 'picked_up'); // legality on the loaded row, before any side effect
            $this->repo->applyState($deliveryId, 'picked_up', ['picked_up_at' => date('Y-m-d H:i:s')]);
            $this->audit?->append(['type' => 'user', 'id' => $operatorUserId], 'deliveries.picked_up', 'delivery:' . $deliveryId,
                ['from' => $from, 'to' => 'picked_up'], $this->requestId);
            $this->notifications?->enqueueForOrder('delivery.picked_up', $order, $deliveryId); // outbox row joins this transaction
            return $this->repo->findById($deliveryId) ?? [];
        });
    }

    /**
     * picked_up → delivered, PIN-gated (spec D7). The order must be ready.
     * Wrong PIN increments attempts (typed PIN_INVALID); once 5 wrong attempts
     * are recorded the next attempt fails the delivery with reason pin_exhausted.
     * A correct PIN consumes the hash and drives the order ready→completed.
     */
    public function deliver(int $deliveryId, string $pin, int $operatorUserId): array
    {
        $this->assertPermission($operatorUserId, 'deliveries.assign', $deliveryId);
        $delivery = $this->repo->findById($deliveryId) ?? throw new DomainException('DELIVERY_NOT_FOUND');
        DeliveryStateMap::create()->transition((string)$delivery['state'], 'delivered'); // legality before any side effect
        $order = $this->orderById((int)$delivery['order_id']) ?? throw new DomainException('ORDER_NOT_FOUND');
        $this->assertBranchScope($operatorUserId, (int)$order['branch_id'], $deliveryId, 'deliveries.assign');
        if ((string)$order['status'] !== 'ready') throw new DomainException('ORDER_NOT_READY');
        $attempts = (int)$delivery['pin_attempts'];
        if ($attempts >= self::MAX_PIN_ATTEMPTS) {
            $this->db->transaction(function () use ($deliveryId, $operatorUserId): void {
                $this->repo->applyState($deliveryId, 'failed', ['failed_reason' => 'pin_exhausted']);
                $this->audit?->append(['type' => 'user', 'id' => $operatorUserId], 'deliveries.failed', 'delivery:' . $deliveryId,
                    ['from' => 'picked_up', 'to' => 'failed', 'reason' => 'pin_exhausted'], $this->requestId);
            });
            throw new DomainException('PIN_EXHAUSTED');
        }
        $key = $this->repo->ensurePinKey((int)$order['business_id']);
        if (!Pin::verify(trim($pin), is_string($delivery['pin_hash']) ? $delivery['pin_hash'] : null)) {
            $this->repo->applyState($deliveryId, (string)$delivery['state'], ['pin_attempts' => $attempts + 1]);
            throw new DomainException('PIN_INVALID');
        }
        return $this->db->transaction(function () use ($delivery, $deliveryId, $order, $operatorUserId): array {
            $this->repo->applyState($deliveryId, 'delivered', ['delivered_at' => date('Y-m-d H:i:s'), 'pin_hash' => null]); // consume
            $this->audit?->append(['type' => 'user', 'id' => $operatorUserId], 'deliveries.delivered', 'delivery:' . $deliveryId,
                ['from' => (string)$delivery['state'], 'to' => 'delivered', 'order_id' => (int)$order['id']], $this->requestId);
            $this->notifications?->enqueueForOrder('delivery.delivered', $order, $deliveryId); // dedup: delivery orders never get order.completed
            $this->operations()->completeFromDelivery((int)$order['id']);
            return $this->repo->findById($deliveryId) ?? [];
        });
    }

    /** assigned|picked_up → failed with a mandatory reason. Requires deliveries.assign. */
    public function fail(int $deliveryId, string $reason, int $operatorUserId): array
    {
        if (trim($reason) === '') throw new InvalidArgumentException('FAIL_REASON_REQUIRED');
        $this->assertPermission($operatorUserId, 'deliveries.assign', $deliveryId);
        return $this->db->transaction(function () use ($deliveryId, $reason, $operatorUserId): array {
            $delivery = $this->repo->findById($deliveryId) ?? throw new DomainException('DELIVERY_NOT_FOUND');
            $order = $this->orderById((int)$delivery['order_id']) ?? throw new DomainException('ORDER_NOT_FOUND');
            $this->assertBranchScope($operatorUserId, (int)$order['branch_id'], $deliveryId, 'deliveries.assign');
            $from = (string)$delivery['state'];
            DeliveryStateMap::create()->transition($from, 'failed'); // legality before any side effect
            $this->repo->applyState($deliveryId, 'failed', ['failed_reason' => trim($reason)]);
            $this->audit?->append(['type' => 'user', 'id' => $operatorUserId], 'deliveries.failed', 'delivery:' . $deliveryId,
                ['from' => $from, 'to' => 'failed', 'reason' => trim($reason)], $this->requestId);
            return $this->repo->findById($deliveryId) ?? [];
        });
    }

    /** Direct cancel (pending|assigned|picked_up → cancelled). Requires deliveries.manage; the order-cancel cascade needs none. */
    public function cancel(int $deliveryId, int $operatorUserId): array
    {
        $this->assertPermission($operatorUserId, 'deliveries.manage', $deliveryId);
        return $this->db->transaction(function () use ($deliveryId, $operatorUserId): array {
            $delivery = $this->repo->findById($deliveryId) ?? throw new DomainException('DELIVERY_NOT_FOUND');
            $order = $this->orderById((int)$delivery['order_id']) ?? throw new DomainException('ORDER_NOT_FOUND');
            $this->assertBranchScope($operatorUserId, (int)$order['branch_id'], $deliveryId, 'deliveries.manage');
            $from = (string)$delivery['state'];
            DeliveryStateMap::create()->transition($from, 'cancelled'); // legality before any side effect
            $this->repo->applyState($deliveryId, 'cancelled');
            $this->audit?->append(['type' => 'user', 'id' => $operatorUserId], 'deliveries.cancelled', 'delivery:' . $deliveryId,
                ['from' => $from, 'to' => 'cancelled'], $this->requestId);
            return $this->repo->findById($deliveryId) ?? [];
        });
    }

    /** Recompute the PIN for public redisplay; only while assigned/picked_up (spec D9 hides it for pending and terminal states). */
    public function pinForOrder(array $order): ?string
    {
        $delivery = $this->repo->findByOrderId((int)$order['id']);
        if (!$delivery || !in_array((string)$delivery['state'], ['assigned', 'picked_up'], true)) return null;
        $key = $this->repo->ensurePinKey((int)$order['business_id']);
        return Pin::code($key, (int)$delivery['id'], (int)$order['id']);
    }

    /**
     * Public token-page context (spec D9): delivery state, courier name when assigned,
     * and the recomputed PIN — which pinForOrder() returns only while assigned/picked_up,
     * so terminal states never expose a code. The PIN value is never audited or logged.
     */
    public function publicContextForOrder(array $order): ?array
    {
        $delivery = $this->repo->findByOrderId((int)$order['id']);
        if (!$delivery) return null;
        $person = $delivery['delivery_person_id'] !== null ? $this->repo->person((int)$delivery['delivery_person_id']) : null;
        return ['state' => (string)$delivery['state'], 'courier' => $person !== null ? (string)$person['name'] : null, 'pin' => $this->pinForOrder($order)];
    }

    public function hasPermission(int $userId, string $key): bool
    {
        $rows = $this->db->select('SELECT DISTINCT p.permission_key FROM user_roles ur INNER JOIN role_permissions rp ON rp.role_id=ur.role_id INNER JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=?', [$userId]);
        foreach ($rows as $row) if ((string)$row['permission_key'] === $key) return true;
        return false;
    }

    // ---- internals ----

    private function assignWithinTransaction(int $deliveryId, int $personId, int $operatorUserId, string $auditAction): array
    {
        $delivery = $this->repo->findById($deliveryId) ?? throw new DomainException('DELIVERY_NOT_FOUND');
        $order = $this->orderById((int)$delivery['order_id']) ?? throw new DomainException('ORDER_NOT_FOUND');
        $this->assertBranchScope($operatorUserId, (int)$order['branch_id'], $deliveryId, 'deliveries.assign');
        if (!in_array((string)$order['status'], self::ASSIGNABLE_ORDER_STATUSES, true)) throw new DomainException('ORDER_NOT_ASSIGNABLE');
        $person = $this->repo->person($personId);
        if (!$person || (int)$person['is_active'] !== 1 || !$this->personInBranch($personId, (int)$order['branch_id'])) {
            throw new DomainException('PERSON_NOT_AVAILABLE');
        }
        $from = (string)$delivery['state'];
        DeliveryStateMap::create()->transition($from, 'assigned'); // pending→assigned or assigned→assigned (reassign)
        $key = $this->repo->ensurePinKey((int)$order['business_id']);
        $this->repo->applyState($deliveryId, 'assigned', [
            'delivery_person_id' => $personId,
            'assigned_at' => date('Y-m-d H:i:s'),
            'pin_hash' => Pin::hash(Pin::code($key, $deliveryId, (int)$order['id'])),
            'pin_attempts' => 0,
        ]);
        $this->audit?->append(['type' => 'user', 'id' => $operatorUserId], $auditAction, 'delivery:' . $deliveryId,
            ['from' => $from, 'to' => 'assigned', 'person_id' => $personId], $this->requestId);
        $this->notifications?->enqueueForOrder('delivery.assigned', $order, $deliveryId); // assign + reassign (design D8); context ids only, PIN stays out
        return $this->repo->findById($deliveryId) ?? [];
    }

    private function assertPermission(int $userId, string $permission, int $deliveryId): void
    {
        if ($this->hasPermission($userId, $permission)) return;
        $this->audit?->append(['type' => 'user', 'id' => $userId], 'authz.denied', 'delivery:' . $deliveryId,
            ['required' => $permission], $this->requestId);
        throw new DomainException('PERMISSION_DENIED');
    }

    private function assertBranchScope(int $userId, int $branchId, int $deliveryId, string $permission): void
    {
        if ($this->db->select('SELECT 1 FROM user_branches WHERE user_id=? AND branch_id=? LIMIT 1', [$userId, $branchId])) return;
        $this->audit?->append(['type' => 'user', 'id' => $userId], 'authz.denied', 'delivery:' . $deliveryId,
            ['required' => $permission, 'scope' => 'branch', 'branch_id' => $branchId], $this->requestId);
        throw new DomainException('PERMISSION_DENIED');
    }

    private function personInBranch(int $personId, int $branchId): bool
    {
        return (bool)$this->db->select('SELECT 1 FROM delivery_person_branches WHERE person_id=? AND branch_id=? LIMIT 1', [$personId, $branchId]);
    }

    private function orderById(int $orderId): ?array
    {
        $rows = $this->db->select('SELECT * FROM orders WHERE id=? LIMIT 1', [$orderId]);
        return $rows[0] ?? null;
    }

    /** Built lazily to avoid a constructor cycle: the order gateway also takes a DeliveryRepository. */
    private function operations(): OrderOperationsService
    {
        return new OrderOperationsService($this->db, new OrderRepository($this->db), new StockService($this->db),
            new PaymentRepository($this->db), new PaymentService($this->db, new PaymentRepository($this->db), new OrderRepository($this->db), $this->audit, $this->requestId, null, null, $this->notifications),
            $this->audit, $this->requestId, $this->repo, $this->notifications);
    }
}
