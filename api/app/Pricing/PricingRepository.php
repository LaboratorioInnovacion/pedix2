<?php declare(strict_types=1);
namespace VO\Pricing;

use PDO;
use VO\Catalog\CatalogPriceResolver;

final class PricingRepository
{
    public function __construct(private PDO $pdo) {}

    public function branch(int $branchId): ?array { return $this->one('SELECT * FROM branches WHERE id=? AND is_active=1 LIMIT 1', [$branchId]); }
    public function minimums(int $branchId): array
    {
        $rows = $this->many("SELECT setting_key,setting_value FROM branch_settings WHERE branch_id=? AND setting_key IN ('minimum_pickup_cents','minimum_delivery_cents')", [$branchId]);
        $out = ['pickup' => 0, 'delivery' => 0];
        foreach ($rows as $r) if ($r['setting_key'] === 'minimum_pickup_cents') $out['pickup'] = (int) $r['setting_value']; else $out['delivery'] = (int) $r['setting_value'];
        return $out;
    }
    public function line(int $branchId, int $itemId, ?int $variantId): ?array
    {
        $item = $this->one('SELECT * FROM catalog_items WHERE id=? AND is_active=1 AND archived_at IS NULL LIMIT 1', [$itemId]);
        if (!$item) return null;
        $branchItem = $this->one('SELECT * FROM branch_items WHERE branch_id=? AND item_id=? AND is_available=1 LIMIT 1', [$branchId, $itemId]);
        if (!$branchItem) return null;
        $variant = $branchVariant = null;
        if ($variantId !== null) {
            $variant = $this->one('SELECT * FROM item_variants WHERE id=? AND item_id=? AND is_active=1 AND archived_at IS NULL LIMIT 1', [$variantId, $itemId]);
            if (!$variant) return null;
            $branchVariant = $this->one('SELECT * FROM branch_variants WHERE branch_id=? AND variant_id=? AND is_available=1 LIMIT 1', [$branchId, $variantId]);
        }
        $price = CatalogPriceResolver::resolve($item, $branchItem, $variant, $branchVariant);
        if ($price === null) return null;
        return ['item' => $item, 'variant' => $variant, 'unit_price_cents' => $price];
    }
    public function modifiers(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        $sql = 'SELECT * FROM modifiers WHERE is_active=1 AND archived_at IS NULL AND id IN (' . rtrim(str_repeat('?,', count($ids)), ',') . ')';
        return $this->many($sql, $ids);
    }
    public function promotions(): array
    {
        return $this->many("SELECT p.*,r.scope,r.scope_id,r.payment_method,r.discount_basis_points,r.fixed_cents,r.scheduled_price_cents,r.min_amount_cents FROM promotions p JOIN promotion_rules r ON r.promotion_id=p.id WHERE p.archived_at IS NULL AND (p.starts_at IS NULL OR p.starts_at<=NOW()) AND (p.ends_at IS NULL OR p.ends_at>=NOW()) AND (p.usage_limit IS NULL OR p.times_used<p.usage_limit) ORDER BY p.priority,p.id");
    }
    public function coupon(string $code): ?array
    {
        return $this->one("SELECT * FROM coupons WHERE UPPER(code)=UPPER(?) AND archived_at IS NULL AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) AND (usage_limit IS NULL OR times_used<usage_limit) LIMIT 1", [$code]);
    }
    private function many(string $sql, array $p = []): array { $s = $this->pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function one(string $sql, array $p = []): ?array { $r = $this->many($sql, $p); return $r[0] ?? null; }
}
