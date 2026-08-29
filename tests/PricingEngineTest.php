<?php declare(strict_types=1);
namespace Tests;

use VO\Database\MigrationRunner;
use VO\Pricing\PricingRepository;
use VO\Pricing\PricingService;

final class PricingEngineTest extends TestCase
{
    public function testEffectivePriceChainScheduledPriceAndFormulaStages(): void
    {
        $s = $this->db();
        try {
            $ids = $this->catalog($s, base: 10000, branchItem: 9000, variant: 8000, branchVariant: 7000, categoryId: 1);
            $this->promo($s, 'Lunch', 'scheduled_price', 1, false, 'item', $ids['item'], ['scheduled_price_cents' => 6000]);
            $this->promo($s, 'Product 10', 'product_pct', 10, true, 'item', $ids['item'], ['discount_basis_points' => 1000]);
            $this->promo($s, 'Product fixed', 'product_fixed', 20, true, 'item', $ids['item'], ['fixed_cents' => 300]);
            $this->promo($s, 'Category 5', 'category_pct', 30, true, 'category', 1, ['discount_basis_points' => 500]);
            $this->promo($s, 'Order 10', 'min_amount_pct', 40, true, 'order', null, ['discount_basis_points' => 1000, 'min_amount_cents' => 4000]);
            $this->promo($s, 'Card 10', 'payment_pct', 50, true, 'payment_method', null, ['payment_method' => 'card', 'discount_basis_points' => 1000]);
            $this->coupon($s, 'SAVE10', 1000, 4000);

            $quote = $this->service($s)->quote(['branch_id' => 1, 'fulfillment' => 'delivery', 'payment_method' => 'card', 'delivery_fee_cents' => 1200, 'coupon_code' => 'save10', 'items' => [['item_id' => $ids['item'], 'variant_id' => $ids['variant'], 'quantity' => 1]]]);

            $this->assertSame(6000, $quote['lines'][0]['unit_price_cents']);
            $this->assertSame(6000, $quote['gross_items_cents']);
            $this->assertSame(1200, $quote['item_promotions_cents']);
            $this->assertSame(480, $quote['order_promotions_cents']);
            $this->assertSame(432, $quote['coupon_discount_cents']);
            $this->assertSame(389, $quote['payment_discount_cents']);
            $this->assertSame(3499, $quote['merchandise_total_cents']);
            $this->assertSame(4699, $quote['grand_total_cents']);
        } finally { $s->drop(); }
    }

    public function testStackabilityCouponValidityMinimumsPriceChangedRoundingAndSideEffects(): void
    {
        $s = $this->db();
        try {
            $ids = $this->catalog($s, base: 1005, branchItem: null, variant: null, branchVariant: null, categoryId: 1);
            $this->promo($s, 'Archived', 'product_pct', 1, true, 'item', $ids['item'], ['discount_basis_points' => 9000, 'archived' => true]);
            $this->promo($s, 'Expired', 'product_pct', 2, true, 'item', $ids['item'], ['discount_basis_points' => 9000, 'starts_at' => '2020-01-01 00:00:00', 'ends_at' => '2020-01-02 00:00:00']);
            $this->promo($s, 'Product 50', 'product_pct', 3, false, 'item', $ids['item'], ['discount_basis_points' => 5000]);
            $this->promo($s, 'Blocked later', 'product_fixed', 4, true, 'item', $ids['item'], ['fixed_cents' => 999]);
            $this->promo($s, 'Order half even', 'min_amount_pct', 5, true, 'order', null, ['discount_basis_points' => 100, 'min_amount_cents' => 500]);
            $this->coupon($s, 'USED', 1000, 100, usageLimit: 1, timesUsed: 1);
            $this->coupon($s, 'FUTURE', 1000, 100, starts: '2099-01-01 00:00:00');
            $this->coupon($s, 'HIGHMIN', 1000, 10000);
            $this->coupon($s, 'OK', 1000, 400);
            $s->pdo->exec("INSERT INTO branch_settings (branch_id,setting_key,setting_value) VALUES (1,'minimum_pickup_cents','300'),(1,'minimum_delivery_cents','2000')");

            $svc = $this->service($s);
            $fail = $svc->quote(['branch_id' => 1, 'fulfillment' => 'delivery', 'coupon_code' => 'OK', 'items' => [['item_id' => $ids['item'], 'quantity' => 1]], 'accepted_grand_total_cents' => 999, 'accepted_lines' => [['item_id' => $ids['item'], 'line_total_cents' => 999]]]);
            $this->assertSame(false, $fail['minimum_order']['met']);
            $this->assertSame(502, $fail['item_promotions_cents']);
            $this->assertSame(5, $fail['order_promotions_cents']);
            $this->assertSame(50, $fail['coupon_discount_cents']);
            $this->assertSame(true, $fail['price_changed']);
            $this->assertSame(448, $fail['grand_total_cents']);

            $ok = $svc->quote(['branch_id' => 1, 'fulfillment' => 'pickup', 'coupon_code' => 'HIGHMIN', 'items' => [['item_id' => $ids['item'], 'quantity' => 1]], 'accepted_grand_total_cents' => 498, 'accepted_lines' => [['item_id' => $ids['item'], 'line_total_cents' => 1005]]]);
            $this->assertSame(true, $ok['minimum_order']['met']);
            $this->assertSame(0, $ok['coupon_discount_cents']);
            $this->assertSame(false, $ok['price_changed']);
            $this->assertSame(0, (int) $s->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'order.%' OR action LIKE 'payment.%' OR action LIKE 'stock.%'")->fetchColumn());
        } finally { $s->drop(); }
    }

    private function db(): ScratchDatabase { $s = ScratchDatabase::create('vo_pricing_test_'); (new MigrationRunner($s->connection(), dirname(__DIR__) . '/api/database/migrations'))->run(); $s->pdo->exec("INSERT INTO businesses (name,slug) VALUES ('Demo','demo')"); $s->pdo->exec("INSERT INTO branches (business_id,name,is_active) VALUES (1,'Main',1)"); return $s; }
    private function service(ScratchDatabase $s): PricingService { return new PricingService(new PricingRepository($s->pdo)); }
    private function catalog(ScratchDatabase $s, int $base, ?int $branchItem, ?int $variant, ?int $branchVariant, int $categoryId): array { $s->pdo->exec("INSERT IGNORE INTO categories (id,name,slug) VALUES ($categoryId,'Food','food$categoryId')"); $s->pdo->exec("INSERT INTO catalog_items (category_id,type,name,slug,base_price_cents,is_active) VALUES ($categoryId,'product','Burger',CONCAT('burger',RAND()),$base,1)"); $item = (int) $s->pdo->lastInsertId(); $variantId = null; if ($variant !== null || $branchVariant !== null) { $s->pdo->exec("INSERT INTO item_variants (item_id,name,price_cents,is_active) VALUES ($item,'Regular'," . ($variant ?? 'NULL') . ",1)"); $variantId = (int) $s->pdo->lastInsertId(); } $s->pdo->exec("INSERT INTO branch_items (branch_id,item_id,is_available,price_override_cents) VALUES (1,$item,1," . ($branchItem ?? 'NULL') . ")"); if ($variantId !== null) $s->pdo->exec("INSERT INTO branch_variants (branch_id,variant_id,is_available,price_override_cents) VALUES (1,$variantId,1," . ($branchVariant ?? 'NULL') . ")"); return ['item' => $item, 'variant' => $variantId]; }
    private function promo(ScratchDatabase $s, string $name, string $type, int $priority, bool $stack, string $scope, ?int $scopeId, array $rule): void { $starts = $rule['starts_at'] ?? null; $ends = $rule['ends_at'] ?? null; $archived = !empty($rule['archived']) ? date('Y-m-d H:i:s') : null; $s->pdo->prepare('INSERT INTO promotions (name,type,priority,is_stackable,starts_at,ends_at,archived_at) VALUES (?,?,?,?,?,?,?)')->execute([$name,$type,$priority,$stack ? 1 : 0,$starts,$ends,$archived]); $pid = (int) $s->pdo->lastInsertId(); $s->pdo->prepare('INSERT INTO promotion_rules (promotion_id,scope,scope_id,payment_method,discount_basis_points,fixed_cents,scheduled_price_cents,min_amount_cents) VALUES (?,?,?,?,?,?,?,?)')->execute([$pid,$scope,$scopeId,$rule['payment_method'] ?? null,$rule['discount_basis_points'] ?? null,$rule['fixed_cents'] ?? null,$rule['scheduled_price_cents'] ?? null,$rule['min_amount_cents'] ?? null]); }
    private function coupon(ScratchDatabase $s, string $code, int $bp, int $min, ?string $starts = null, ?int $usageLimit = null, int $timesUsed = 0): void { $s->pdo->prepare('INSERT INTO coupons (code,discount_basis_points,min_amount_cents,starts_at,usage_limit,times_used) VALUES (?,?,?,?,?,?)')->execute([$code,$bp,$min,$starts,$usageLimit,$timesUsed]); }
}
