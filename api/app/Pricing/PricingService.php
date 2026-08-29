<?php declare(strict_types=1);
namespace VO\Pricing;

use InvalidArgumentException;
use VO\Domain\Money;

final class PricingService
{
    public function __construct(private PricingRepository $repo) {}

    public function quote(array $request): array
    {
        $branchId = $this->positive($request['branch_id'] ?? null, 'branch_id');
        if (!$this->repo->branch($branchId)) throw new InvalidArgumentException('Invalid branch.');
        $fulfillment = (string)($request['fulfillment'] ?? 'pickup');
        if (!in_array($fulfillment, ['pickup','delivery'], true)) throw new InvalidArgumentException('Invalid fulfillment.');
        $delivery = $this->cents($request['delivery_fee_cents'] ?? 0, 'delivery_fee_cents');
        $promos = $this->repo->promotions();
        $lines = $this->buildLines($branchId, $request['items'] ?? [], $promos);
        $gross = array_sum(array_column($lines, 'gross_line_cents'));
        $minimums = $this->repo->minimums($branchId); $required = $minimums[$fulfillment] ?? 0;
        [$itemDiscount, $itemApplications] = $this->applyItemPromotions($lines, $promos);
        $afterItems = $gross - $itemDiscount;
        [$orderDiscount, $orderApplications] = $this->applyOrderPromotions($afterItems, $promos);
        $afterAuto = $afterItems - $orderDiscount;
        [$couponDiscount, $coupon] = $this->applyCoupon($afterAuto, trim((string)($request['coupon_code'] ?? '')));
        $afterCoupon = $afterAuto - $couponDiscount;
        [$paymentDiscount, $paymentApplications] = $this->applyPayment($afterCoupon, (string)($request['payment_method'] ?? ''), $promos);
        $merchandise = $afterCoupon - $paymentDiscount;
        $grand = $merchandise + $delivery;
        $result = [
            'price_changed' => false,
            'lines' => $lines,
            'discounts' => array_merge($itemApplications, $orderApplications, $coupon ? [$coupon] : [], $paymentApplications),
            'gross_items_cents' => $gross,
            'item_promotions_cents' => $itemDiscount,
            'order_promotions_cents' => $orderDiscount,
            'coupon_discount_cents' => $couponDiscount,
            'payment_discount_cents' => $paymentDiscount,
            'merchandise_total_cents' => $merchandise,
            'delivery_fee_cents' => $delivery,
            'grand_total_cents' => $grand,
            'minimum_order' => ['fulfillment' => $fulfillment, 'required_cents' => $required, 'basis_cents' => $gross, 'met' => $gross >= $required],
        ];
        $result['price_changed'] = $this->changed($request, $result);
        return $result;
    }

    private function buildLines(int $branchId, array $items, array $promos): array
    {
        if (!$items) throw new InvalidArgumentException('At least one item is required.');
        $out = [];
        foreach ($items as $i => $row) {
            $itemId = $this->positive($row['item_id'] ?? null, 'item_id'); $variantId = isset($row['variant_id']) ? $this->positive($row['variant_id'], 'variant_id') : null; $qty = $this->positive($row['quantity'] ?? 1, 'quantity');
            $line = $this->repo->line($branchId, $itemId, $variantId); if (!$line) throw new InvalidArgumentException('Invalid catalog line.');
            $unit = $this->scheduledPrice((int)$line['unit_price_cents'], $itemId, $promos);
            $modTotal = 0; foreach ($this->repo->modifiers($row['modifier_ids'] ?? []) as $m) $modTotal += (int)$m['price_delta_cents'];
            $grossUnit = $unit + $modTotal; $grossLine = $grossUnit * $qty;
            $out[] = ['index' => $i, 'item_id' => $itemId, 'variant_id' => $variantId, 'category_id' => $line['item']['category_id'] === null ? null : (int)$line['item']['category_id'], 'quantity' => $qty, 'unit_price_cents' => $unit, 'modifiers_total_cents' => $modTotal, 'gross_unit_cents' => $grossUnit, 'gross_line_cents' => $grossLine, 'line_total_cents' => $grossLine];
        }
        return $out;
    }
    private function scheduledPrice(int $current, int $itemId, array $promos): int { foreach ($promos as $p) if ($p['type'] === 'scheduled_price' && $p['scope'] === 'item' && (int)$p['scope_id'] === $itemId && $p['scheduled_price_cents'] !== null) return (int)$p['scheduled_price_cents']; return $current; }
    private function applyItemPromotions(array $lines, array $promos): array
    {
        $total = 0; $apps = []; $blocked = [];
        foreach ($promos as $p) {
            if (!in_array($p['type'], ['product_pct','product_fixed','category_pct'], true)) continue;
            foreach ($lines as $l) {
                $scope = 'item:' . $l['index']; if (isset($blocked[$scope]) || !$this->matchesLine($p, $l)) continue;
                $discount = $p['type'] === 'product_fixed' ? min((int)$p['fixed_cents'] * $l['quantity'], $l['line_total_cents']) : $this->pct($l['line_total_cents'], (int)$p['discount_basis_points']);
                if ($discount <= 0) continue; $total += $discount; $apps[] = $this->app($p, $discount, 'item', $l['index']);
                if (!(bool)$p['is_stackable']) $blocked[$scope] = true;
            }
        }
        return [$total, $apps];
    }
    private function applyOrderPromotions(int $base, array $promos): array
    {
        $total = 0; $apps = []; $blocked = false;
        foreach ($promos as $p) {
            if ($p['type'] !== 'min_amount_pct' || $blocked || $p['scope'] !== 'order' || $base < (int)($p['min_amount_cents'] ?? 0)) continue;
            $discount = $this->pct($base - $total, (int)$p['discount_basis_points']); if ($discount <= 0) continue;
            $total += $discount; $apps[] = $this->app($p, $discount, 'order', null); if (!(bool)$p['is_stackable']) $blocked = true;
        }
        return [$total, $apps];
    }
    private function applyCoupon(int $base, string $code): array
    {
        if ($code === '') return [0, null]; $c = $this->repo->coupon($code);
        if (!$c || $base < (int)($c['min_amount_cents'] ?? 0)) return [0, null];
        $discount = $this->pct($base, (int)$c['discount_basis_points']);
        return [$discount, ['kind' => 'coupon', 'code' => $c['code'], 'amount_cents' => $discount]];
    }
    private function applyPayment(int $base, string $method, array $promos): array
    {
        $total = 0; $apps = [];
        foreach ($promos as $p) if ($p['type'] === 'payment_pct' && $p['scope'] === 'payment_method' && (string)$p['payment_method'] === $method) { $d = $this->pct($base, (int)$p['discount_basis_points']); if ($d > 0) { $total += $d; $apps[] = $this->app($p, $d, 'payment', null); } }
        return [$total, $apps];
    }
    private function matchesLine(array $p, array $l): bool { return ($p['scope'] === 'item' && (int)$p['scope_id'] === $l['item_id']) || ($p['scope'] === 'category' && $l['category_id'] !== null && (int)$p['scope_id'] === $l['category_id']); }
    private function app(array $p, int $amount, string $scope, ?int $line): array { return ['kind' => 'promotion', 'promotion_id' => (int)$p['id'], 'type' => $p['type'], 'scope' => $scope, 'line_index' => $line, 'amount_cents' => $amount]; }
    private function changed(array $req, array $r): bool
    {
        if (isset($req['accepted_grand_total_cents']) && (int)$req['accepted_grand_total_cents'] !== $r['grand_total_cents']) return true;
        foreach (($req['accepted_lines'] ?? []) as $i => $line) if (isset($line['line_total_cents'], $r['lines'][$i]) && (int)$line['line_total_cents'] !== $r['lines'][$i]['line_total_cents']) return true;
        return false;
    }
    private function pct(int $cents, int $bp): int { return Money::fromCents($cents)->pct($bp)->cents(); }
    private function positive(mixed $v, string $name): int { $i = filter_var($v, FILTER_VALIDATE_INT); if ($i === false || $i <= 0) throw new InvalidArgumentException('Invalid ' . $name . '.'); return (int)$i; }
    private function cents(mixed $v, string $name): int { $i = filter_var($v, FILTER_VALIDATE_INT); if ($i === false || $i < 0) throw new InvalidArgumentException('Invalid ' . $name . '.'); return (int)$i; }
}
