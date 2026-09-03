<?php declare(strict_types=1);
namespace VO\Delivery;

use VO\Database\Connection;

/**
 * Read/write access for delivery zones, delivery persons, and per-order delivery rows.
 * Zone matching follows spec D2: normalized substring match, first active zone by id wins.
 */
final class DeliveryRepository
{
    public function __construct(private Connection $db) {}

    /** Lazily provision the per-business HMAC key for delivery PINs; safe to call repeatedly. */
    public function ensurePinKey(int $businessId): string
    {
        $this->db->execute(
            'INSERT INTO business_settings(business_id,setting_key,setting_value) SELECT ?,?,? WHERE NOT EXISTS (SELECT 1 FROM business_settings WHERE business_id=? AND setting_key=?)',
            [$businessId, 'delivery.pin_key', bin2hex(random_bytes(32)), $businessId, 'delivery.pin_key']
        );
        $rows = $this->db->select('SELECT setting_value FROM business_settings WHERE business_id=? AND setting_key=? LIMIT 1', [$businessId, 'delivery.pin_key']);
        return (string)($rows[0]['setting_value'] ?? '');
    }

    // --- Zones ---

    public function zone(int $id): ?array
    {
        $rows = $this->db->select('SELECT * FROM delivery_zones WHERE id=? LIMIT 1', [$id]);
        return $rows[0] ?? null;
    }

    /** Active zones of a branch, ordered by id ascending (first match wins). */
    public function activeZonesForBranch(int $branchId): array
    {
        return $this->db->select('SELECT * FROM delivery_zones WHERE branch_id=? AND is_active=1 ORDER BY id ASC', [$branchId]);
    }

    /**
     * Resolve the first active zone of the branch whose terms substring-match
     * the normalized "street city" haystack. Returns null when uncovered.
     */
    public function matchZone(int $branchId, ?string $street, ?string $city): ?array
    {
        $haystack = ZoneMatcher::normalize(trim(($street ?? '') . ' ' . ($city ?? '')));
        if ($haystack === '') return null;
        foreach ($this->activeZonesForBranch($branchId) as $zone) {
            if (ZoneMatcher::matches($haystack, ZoneMatcher::terms((string)$zone['match_terms']))) return $zone;
        }
        return null;
    }

    // --- Delivery persons ---

    public function person(int $id): ?array
    {
        $rows = $this->db->select('SELECT * FROM delivery_persons WHERE id=? LIMIT 1', [$id]);
        return $rows[0] ?? null;
    }

    /** Active persons linked to a branch through the unique person/branch pivot. */
    public function personsForBranch(int $branchId): array
    {
        return $this->db->select('SELECT p.* FROM delivery_persons p JOIN delivery_person_branches pb ON pb.person_id=p.id WHERE pb.branch_id=? AND p.is_active=1 ORDER BY p.id ASC', [$branchId]);
    }

    // --- Delivery rows (one per order) ---

    public function findByOrderId(int $orderId): ?array
    {
        $rows = $this->db->select('SELECT * FROM deliveries WHERE order_id=? LIMIT 1', [$orderId]);
        return $rows[0] ?? null;
    }

    public function findById(int $id): ?array
    {
        $rows = $this->db->select('SELECT * FROM deliveries WHERE id=? LIMIT 1', [$id]);
        return $rows[0] ?? null;
    }

    /** Delivery rows keyed by order_id, for the visible board/detail slice. */
    public function forOrderIds(array $orderIds): array
    {
        if ($orderIds === []) return [];
        $in = implode(',', array_fill(0, count($orderIds), '?'));
        $out = [];
        foreach ($this->db->select("SELECT * FROM deliveries WHERE order_id IN ($in)", $orderIds) as $row) {
            $out[(int)$row['order_id']] = $row;
        }
        return $out;
    }

    /** Create the pending delivery row for a delivery-fulfillment order. */
    public function createPendingForOrder(int $orderId): int
    {
        $this->db->execute("INSERT INTO deliveries (order_id,state) VALUES (?,'pending')", [$orderId]);
        return (int)($this->db->select('SELECT LAST_INSERT_ID() id')[0]['id'] ?? 0);
    }

    /**
     * Lifecycle write: set state plus optional timestamp/reason/person columns.
     * Caller-owned by DeliveryService (Unit B); kept generic on purpose.
     */
    public function applyState(int $deliveryId, string $state, array $fields = []): bool
    {
        $sets = ['state=?']; $params = [$state];
        foreach (['delivery_person_id','assigned_at','picked_up_at','delivered_at','failed_reason','pin_hash','pin_attempts'] as $col) {
            if (array_key_exists($col, $fields)) { $sets[] = "$col=?"; $params[] = $fields[$col]; }
        }
        $params[] = $deliveryId;
        return $this->db->execute('UPDATE deliveries SET ' . implode(',', $sets) . ' WHERE id=?', $params) > 0;
    }

    /**
     * Order-cancel cascade (spec D8): cancel the order's active delivery
     * (pending/assigned/picked_up) inside the caller's transaction — never opens
     * one itself (no nesting). Returns [id, from] when a row was cancelled, null otherwise.
     */
    public function cancelActiveForOrder(int $orderId): ?array
    {
        $delivery = $this->findByOrderId($orderId);
        if (!$delivery || !in_array((string)$delivery['state'], DeliveryStateMap::ACTIVE_STATES, true)) return null;
        $this->applyState((int)$delivery['id'], 'cancelled');
        return ['id' => (int)$delivery['id'], 'from' => (string)$delivery['state']];
    }
}
