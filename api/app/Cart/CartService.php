<?php declare(strict_types=1);
namespace VO\Cart;

use DomainException; use InvalidArgumentException; use VO\Delivery\DeliveryRepository; use VO\Delivery\DeliveryUnavailableException; use VO\Pricing\PricingRepository; use VO\Pricing\PricingService;

class CartValidationException extends DomainException { public function __construct(string $message, public int $status=422) { parent::__construct($message); } }

final class CartService
{
    public function __construct(private CartRepository $repo, private PricingService $pricing, private ?DeliveryRepository $delivery = null) {}
    public function get(string $hash): array { return $this->state($this->repo->findOrCreateByTokenHash($hash)); }
    public function addToCart(string $hash, int $itemId, ?int $variantId, int $qty, array $modifiers): array
    {
        if ($qty <= 0) throw new CartValidationException('Quantity must be positive.'); $cart=$this->repo->findOrCreateByTokenHash($hash); $item=$this->validItem($itemId);
        if ((int)$item['requires_variant'] === 1 && $variantId === null) throw new CartValidationException('Variant is required.'); if ($variantId !== null && !$this->repo->variant($itemId,$variantId)) throw new CartValidationException('Invalid variant.');
        $branch=$this->repo->branchForItem($itemId,$variantId); if ($branch===null) throw new CartValidationException('Item is unavailable.'); if ($cart['branch_id'] !== null && (int)$cart['branch_id'] !== $branch) throw new CartValidationException('Cart is locked to another branch.', 409);
        $modifierIds=$this->validateModifiers($itemId,$modifiers); $items=$this->repo->items((int)$cart['id']); $hasProduct=$item['type']==='product'; $hasService=$item['type']==='service'; foreach ($items as $r) { $hasProduct = $hasProduct || $r['type']==='product'; $hasService = $hasService || $r['type']==='service'; }
        if (($cart['fulfillment'] ?? 'pickup') === 'delivery' && $hasProduct && $hasService) throw new CartValidationException('Mixed product/service carts are pickup only.', 409);
        $cart['branch_id']=$cart['branch_id'] ?? $branch; $this->repo->updateCart((int)$cart['id'], $cart + ['fulfillment'=>'pickup','coupon_code'=>null,'payment_method'=>null,'address_json'=>null,'customer_note'=>null]);
        $this->repo->upsertItem((int)$cart['id'],$itemId,$variantId,$qty,$modifierIds); return $this->state($this->repo->findByTokenHash($hash) ?: $cart);
    }
    public function updateQty(string $hash, int $id, int $qty): array { if ($qty<=0) throw new CartValidationException('Quantity must be positive.'); $c=$this->repo->findOrCreateByTokenHash($hash); if (!$this->repo->updateQty((int)$c['id'],$id,$qty)) throw new CartValidationException('Cart item not found.',404); return $this->state($c); }
    public function removeItem(string $hash, int $id): array { $c=$this->repo->findOrCreateByTokenHash($hash); if (!$this->repo->removeItem((int)$c['id'],$id)) throw new CartValidationException('Cart item not found.',404); return $this->state($c); }
    public function applyCoupon(string $hash, string $code): array { $c=$this->repo->findOrCreateByTokenHash($hash); $code=trim($code); $coupon=$code===''?null:$this->repo->coupon($code); $c['coupon_code']=$coupon ? (string)$coupon['code'] : null; $this->repo->updateCart((int)$c['id'],$c); return ['applied'=>(bool)$coupon,'message'=>$coupon?'Coupon applied.':'Coupon is invalid, expired, or unavailable.','cart'=>$this->state($this->repo->findByTokenHash($hash) ?: $c)['cart']]; }
    public function setCheckoutData(string $hash, array $d): array
    {
        $c=$this->repo->findOrCreateByTokenHash($hash); $f=(string)($d['fulfillment'] ?? 'pickup'); if (!in_array($f,['pickup','delivery'],true)) throw new CartValidationException('Invalid fulfillment.'); $items=$this->repo->items((int)$c['id']);
        $hasP=$hasS=false; foreach ($items as $r) { $hasP=$hasP||$r['type']==='product'; $hasS=$hasS||$r['type']==='service'; } if ($f==='delivery' && $hasP && $hasS) throw new CartValidationException('Mixed product/service carts are pickup only.',409); if ($f==='delivery' && $hasS) throw new CartValidationException('Services cannot be delivered.',409);
        $branch=isset($d['branch_id'])?(int)$d['branch_id']:$c['branch_id']; if ($c['branch_id']!==null && $branch && (int)$c['branch_id']!==$branch && $items) throw new CartValidationException('Cart is locked to another branch.',409);
        $address=isset($d['address_json']) && is_array($d['address_json']) ? $d['address_json'] : null;
        // Delivery coverage is resolved server-side (spec cart-api R6): the first active
        // zone of the branch matching street+city wins; its rates persist on the cart row.
        [$zoneId,$fee,$payout]=[null,0,0];
        if ($f==='delivery') { $branchId=(int)($branch ?: ($c['branch_id'] ?? 0)); $zone=$this->delivery?->matchZone($branchId,(string)($address['street'] ?? ''),(string)($address['city'] ?? '')); if ($zone===null) throw new DeliveryUnavailableException(); $zoneId=(int)$zone['id']; $fee=(int)$zone['customer_rate_cents']; $payout=(int)$zone['driver_payout_cents']; }
        $c['fulfillment']=$f; $c['branch_id']=$branch?:$c['branch_id']; $c['payment_method']=$d['payment_method'] ?? null; $c['customer_note']=isset($d['customer_note']) ? substr((string)$d['customer_note'],0,500) : null; $c['address_json']=$address ? json_encode($address, JSON_UNESCAPED_SLASHES) : null; $c['delivery_zone_id']=$zoneId; $c['delivery_fee_cents']=$fee; $c['delivery_payout_cents']=$payout; $this->repo->updateCart((int)$c['id'],$c); return $this->state($this->repo->findByTokenHash($hash) ?: $c);
    }
    public function confirmAttempt(string $hash, array $accepted): array { $s=$this->state($this->repo->findOrCreateByTokenHash($hash), $accepted); return $s['pricing']['price_changed'] ? $s : $s + ['confirmation_ready'=>true]; }
    private function state(array $cart, array $accepted=[]): array
    {
        $rows=$this->repo->items((int)$cart['id']); $lines=[]; $un=[]; foreach ($rows as $r) { $mods=array_map(fn($m)=>(int)$m['id'],$r['modifiers']); $lines[]=['id'=>(int)$r['id'],'item_id'=>(int)$r['item_id'],'variant_id'=>$r['variant_id']===null?null:(int)$r['variant_id'],'qty'=>(int)$r['qty'],'modifiers'=>$mods]; if ((int)$r['item_active']!==1 || $r['item_archived'] || ($r['variant_id']!==null && ((int)$r['variant_active']!==1 || $r['variant_archived']))) $un[]=['cart_item_id'=>(int)$r['id'],'item_id'=>(int)$r['item_id']]; }
        $out=['cart'=>['id'=>(int)$cart['id'],'branch_id'=>$cart['branch_id']===null?null:(int)$cart['branch_id'],'fulfillment'=>$cart['fulfillment'],'coupon_code'=>$cart['coupon_code'],'payment_method'=>$cart['payment_method'],'customer_note'=>$cart['customer_note'],'address_json'=>$cart['address_json']?json_decode((string)$cart['address_json'],true):null,'items'=>$lines],'unavailable_lines'=>$un];
        if ($lines && $cart['branch_id']!==null) { $req=['branch_id'=>(int)$cart['branch_id'],'fulfillment'=>$cart['fulfillment'],'coupon_code'=>$cart['coupon_code'] ?? '','payment_method'=>$cart['payment_method'] ?? '','delivery_fee_cents'=>$cart['fulfillment']==='delivery'?(int)($cart['delivery_fee_cents'] ?? 0):0,'items'=>array_map(fn($l)=>['item_id'=>$l['item_id'],'variant_id'=>$l['variant_id'],'quantity'=>$l['qty'],'modifier_ids'=>$l['modifiers']], $lines)] + $accepted; try { $out['pricing']=$this->pricing->quote($req); } catch (InvalidArgumentException $e) { $out['pricing']=['price_changed'=>false,'error'=>$e->getMessage()]; } } else $out['pricing']=['price_changed'=>false,'lines'=>[],'gross_items_cents'=>0,'grand_total_cents'=>0,'minimum_order'=>['fulfillment'=>$cart['fulfillment'],'required_cents'=>0,'basis_cents'=>0,'met'=>true]];
        return $out;
    }
    private function validItem(int $id): array { $i=$this->repo->item($id); if (!$i || (int)$i['is_active']!==1 || $i['archived_at']!==null) throw new CartValidationException('Invalid item.'); return $i; }
    private function validateModifiers(int $itemId, array $ids): array { $ids=array_values(array_map('intval',$ids)); $mods=$this->repo->modifiers($ids); if (count($mods)!==count(array_unique($ids))) throw new CartValidationException('Invalid modifier.'); $by=[]; foreach ($mods as $m) $by[(int)$m['group_id']][]=$m; foreach ($this->repo->groups($itemId) as $g) { $n=count($by[(int)$g['id']] ?? []); if ($n < (int)$g['min_select'] || ($g['max_select'] !== null && $n > (int)$g['max_select'])) throw new CartValidationException('Modifier selection is outside allowed limits.'); } return $ids; }
}
