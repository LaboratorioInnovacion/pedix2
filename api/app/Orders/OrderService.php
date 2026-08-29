<?php declare(strict_types=1);
namespace VO\Orders;

use DomainException; use InvalidArgumentException; use VO\Database\Connection; use VO\Domain\DbIdempotencyStore; use VO\Inventory\StockService; use VO\Pricing\PricingService;

final class PriceChangedException extends DomainException { public function __construct(public array $current) { parent::__construct('PRICE_CHANGED'); } }
final class EmptyCartException extends DomainException {}

final class OrderService
{
    public const TOTAL_KEYS = ['gross_items_cents','item_promotions_cents','order_promotions_cents','coupon_discount_cents','payment_discount_cents','merchandise_total_cents','delivery_fee_cents','grand_total_cents'];
    private StockService $stock;
    public function __construct(private Connection $db, private OrderRepository $orders, private DbIdempotencyStore $idempotency, private PricingService $pricing, ?StockService $stock = null) { $this->stock = $stock ?? new StockService($db); }

    public function createFromCart(string $cartTokenHash, string $idempotencyKey, array $acceptedTotals, ?array $customerInput = null): array
    {
        if ($idempotencyKey === '') throw new InvalidArgumentException('Idempotency key is required.');
        return $this->db->transaction(function () use ($cartTokenHash, $idempotencyKey, $acceptedTotals, $customerInput): array {
            $cart = $this->orders->lockCart($cartTokenHash); if (!$cart || $cart['branch_id'] === null) throw new EmptyCartException('Cart is empty.');
            $businessId = $this->orders->businessIdForBranch((int)$cart['branch_id']); $requestHash = hash('sha256', json_encode([$cartTokenHash,$acceptedTotals,$customerInput], JSON_UNESCAPED_SLASHES));
            if ($replay = $this->idempotency->replay($businessId, $idempotencyKey, $requestHash)) return $replay;
            $items = $this->orders->cartItems((int)$cart['id']); if (!$items) throw new EmptyCartException('Cart is empty.');
            $quote = $this->pricing->quote($this->quoteRequest($cart, $items, $acceptedTotals));
            if ($this->changed($acceptedTotals, $quote)) throw new PriceChangedException($quote);
            $customer = $this->resolveCustomer($businessId, $customerInput ?? []); $address = $cart['address_json'] ? json_decode((string)$cart['address_json'], true) : null;
            $contact = ['name'=>$customer['name'] ?? $customerInput['name'] ?? $address['recipient_name'] ?? null, 'email'=>$customer['email'] ?? $customerInput['email'] ?? null, 'phone'=>$customer['phone'] ?? $customerInput['phone'] ?? $address['phone'] ?? null];
            $this->stock->assertAvailable((int)$cart['branch_id'], $items);
            $order = $this->orders->insertOrder([
                'business_id'=>$businessId,'branch_id'=>(int)$cart['branch_id'],'customer_id'=>$customer['id'] ?? null,'number'=>$this->orders->nextOrderNumber($businessId),'status'=>'pending','public_token'=>bin2hex(random_bytes(32)),'cart_token_hash'=>$cartTokenHash,'fulfillment'=>$cart['fulfillment'],'payment_method'=>$cart['payment_method'],'customer_name'=>$contact['name'],'customer_email'=>$contact['email'],'customer_phone'=>$contact['phone'],'customer_note'=>$cart['customer_note'],'coupon_code'=>$cart['coupon_code'],'confirmed_at'=>date('Y-m-d H:i:s')
            ] + array_intersect_key($quote, array_flip(self::TOTAL_KEYS)), $items, $quote, $cart['fulfillment']==='delivery' ? $address : null);
            $this->stock->reserve($businessId, (int)$cart['branch_id'], (int)$order['id'], $items);
            $this->orders->persistUsage($businessId, (int)$order['id'], $quote);
            $this->orders->clearCartItems((int)$cart['id']);
            $response = $this->response($order, $quote); $this->idempotency->record($businessId, $idempotencyKey, $requestHash, 200, $response, (int)$order['id']); return $response;
        });
    }

    private function quoteRequest(array $cart, array $items, array $accepted): array
    {
        return ['branch_id'=>(int)$cart['branch_id'],'fulfillment'=>$cart['fulfillment'],'coupon_code'=>$cart['coupon_code'] ?? '','payment_method'=>$cart['payment_method'] ?? '','delivery_fee_cents'=>$accepted['delivery_fee_cents'] ?? 0,'items'=>array_map(fn($r)=>['item_id'=>(int)$r['item_id'],'variant_id'=>$r['variant_id']===null?null:(int)$r['variant_id'],'quantity'=>(int)$r['qty'],'modifier_ids'=>array_map(fn($m)=>(int)$m['modifier_id'], $r['modifiers'])], $items)] + $accepted;
    }

    private function changed(array $accepted, array $quote): bool
    {
        foreach (self::TOTAL_KEYS as $k) if (array_key_exists($k, $accepted) && (int)$accepted[$k] !== (int)$quote[$k]) return true;
        if (isset($accepted['accepted_grand_total_cents']) && (int)$accepted['accepted_grand_total_cents'] !== (int)$quote['grand_total_cents']) return true;
        foreach (($accepted['accepted_lines'] ?? []) as $i => $l) if (isset($l['line_total_cents'], $quote['lines'][$i]) && (int)$l['line_total_cents'] !== (int)$quote['lines'][$i]['line_total_cents']) return true;
        return false;
    }

    private function resolveCustomer(int $businessId, array $in): ?array
    {
        if (isset($in['customer_id'])) return $this->db->select('SELECT * FROM customers WHERE business_id=? AND id=? LIMIT 1', [$businessId,(int)$in['customer_id']])[0] ?? null;
        if (($in['email'] ?? '') !== '' && (!empty($in['create_account']) || isset($in['password']))) {
            $hash = isset($in['password']) ? password_hash((string)$in['password'], PASSWORD_DEFAULT) : null;
            $this->db->execute('INSERT INTO customers (business_id,name,email,phone,password_hash) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),phone=VALUES(phone)', [$businessId,(string)($in['name'] ?? $in['email']),(string)$in['email'],$in['phone'] ?? null,$hash]);
            return $this->db->select('SELECT * FROM customers WHERE business_id=? AND email=? LIMIT 1', [$businessId,(string)$in['email']])[0] ?? null;
        }
        return null;
    }

    private function response(array $order, array $quote): array { return ['id'=>(int)$order['id'],'number'=>$order['number'],'public_token'=>$order['public_token'],'status'=>$order['status'],'grand_total'=>(int)$order['grand_total_cents'],'grand_total_cents'=>(int)$order['grand_total_cents'],'breakdown'=>array_intersect_key($quote, array_flip(self::TOTAL_KEYS))]; }
}
