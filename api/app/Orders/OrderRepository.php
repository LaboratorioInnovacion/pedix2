<?php declare(strict_types=1);
namespace VO\Orders;

use VO\Database\Connection;

final class OrderRepository
{
    public function __construct(private Connection $db) {}

    public function lockCart(string $hash): ?array { return $this->one('SELECT * FROM carts WHERE token_hash=? LIMIT 1 FOR UPDATE', [$hash]); }
    public function cartItems(int $cartId): array
    {
        $rows = $this->db->select('SELECT ci.*,i.name item_name,v.name variant_name FROM cart_items ci JOIN catalog_items i ON i.id=ci.item_id LEFT JOIN item_variants v ON v.id=ci.variant_id WHERE ci.cart_id=? ORDER BY ci.id', [$cartId]);
        foreach ($rows as &$r) $r['modifiers'] = $this->db->select('SELECT cim.qty,m.id modifier_id,m.name modifier_name,m.price_delta_cents,g.name group_name FROM cart_item_modifiers cim JOIN modifiers m ON m.id=cim.modifier_id JOIN modifier_groups g ON g.id=m.group_id WHERE cim.cart_item_id=? ORDER BY cim.id', [(int)$r['id']]);
        return $rows;
    }
    public function businessIdForBranch(int $branchId): int { return (int)($this->one('SELECT business_id FROM branches WHERE id=? LIMIT 1', [$branchId])['business_id'] ?? 0); }
    public function couponId(string $code): ?int { $r = $this->one('SELECT id FROM coupons WHERE UPPER(code)=UPPER(?) LIMIT 1', [$code]); return $r ? (int)$r['id'] : null; }

    public function persistUsage(int $businessId, int $orderId, array $quote): void
    {
        foreach ($quote['discounts'] ?? [] as $d) {
            $promotionId = isset($d['promotion_id']) ? (int)$d['promotion_id'] : null; $couponId = isset($d['code']) ? $this->couponId((string)$d['code']) : null;
            if ($promotionId === null && $couponId === null) continue;
            $changed = $this->db->execute('INSERT IGNORE INTO promotion_usage (order_id,business_id,promotion_id,coupon_id,kind,code,amount_cents) VALUES (?,?,?,?,?,?,?)', [$orderId,$businessId,$promotionId,$couponId,$d['kind'],$d['code'] ?? null,(int)$d['amount_cents']]);
            if ($changed === 1 && $promotionId !== null) $this->db->execute('UPDATE promotions SET times_used=times_used+1 WHERE id=?', [$promotionId]);
            if ($changed === 1 && $couponId !== null) $this->db->execute('UPDATE coupons SET times_used=times_used+1 WHERE id=?', [$couponId]);
        }
    }

    public function nextOrderNumber(int $businessId): string
    {
        $this->db->execute('INSERT IGNORE INTO order_counters (business_id,next_number) VALUES (?,1)', [$businessId]);
        $n = (int)($this->one('SELECT next_number FROM order_counters WHERE business_id=? FOR UPDATE', [$businessId])['next_number'] ?? 1);
        $this->db->execute('UPDATE order_counters SET next_number=next_number+1 WHERE business_id=?', [$businessId]);
        return sprintf('B-%06d', $n);
    }

    public function insertOrder(array $o, array $items, array $quote, ?array $address): array
    {
        $cols = ['business_id','branch_id','customer_id','number','status','public_token','cart_token_hash','fulfillment','payment_method','customer_name','customer_email','customer_phone','customer_note','gross_items_cents','item_promotions_cents','order_promotions_cents','coupon_discount_cents','payment_discount_cents','merchandise_total_cents','delivery_fee_cents','grand_total_cents','coupon_code','confirmed_at'];
        $this->db->execute('INSERT INTO orders (' . implode(',', $cols) . ') VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')', array_map(fn($c) => $o[$c] ?? null, $cols));
        $orderId = (int)$this->one('SELECT LAST_INSERT_ID() id')['id'];
        $discount = max(0, (int)$quote['gross_items_cents'] - (int)$quote['merchandise_total_cents']); $remaining = $discount;
        foreach ($items as $i => $item) {
            $line = $quote['lines'][$i]; $net = (int)$line['gross_line_cents']; $share = $i === count($items)-1 ? $remaining : (int)floor($discount * $net / max(1, (int)$quote['gross_items_cents'])); $remaining -= $share; $net -= $share;
            $this->db->execute('INSERT INTO order_items (order_id,branch_id,item_id,variant_id,item_name,variant_name,quantity,unit_price_cents,modifiers_total_cents,gross_unit_cents,gross_line_cents,line_total_cents) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [$orderId,$o['branch_id'],(int)$item['item_id'],$item['variant_id']===null?null:(int)$item['variant_id'],$item['item_name'],$item['variant_name'],(int)$item['qty'],(int)$line['unit_price_cents'],(int)$line['modifiers_total_cents'],(int)$line['gross_unit_cents'],(int)$line['gross_line_cents'],$net]);
            $orderItemId = (int)$this->one('SELECT LAST_INSERT_ID() id')['id'];
            foreach ($item['modifiers'] as $m) $this->db->execute('INSERT INTO order_item_modifiers (order_item_id,modifier_id,group_name,modifier_name,price_delta_cents,qty) VALUES (?,?,?,?,?,?)', [$orderItemId,(int)$m['modifier_id'],$m['group_name'],$m['modifier_name'],(int)$m['price_delta_cents'],(int)$m['qty']]);
        }
        if ($address) $this->db->execute('INSERT INTO order_addresses (order_id,type,recipient_name,phone,street,city,notes,address_json) VALUES (?,?,?,?,?,?,?,?)', [$orderId,$o['fulfillment'],$address['recipient_name'] ?? null,$address['phone'] ?? null,$address['street'] ?? null,$address['city'] ?? null,$address['notes'] ?? null,json_encode($address, JSON_UNESCAPED_SLASHES)]);
        foreach ($quote['discounts'] ?? [] as $d) $this->db->execute('INSERT INTO order_discounts (order_id,promotion_id,coupon_id,kind,type,scope,line_index,code,amount_cents,snapshot_json) VALUES (?,?,?,?,?,?,?,?,?,?)', [$orderId,$d['promotion_id'] ?? null,isset($d['code'])?$this->couponId((string)$d['code']):null,$d['kind'],$d['type'] ?? 'coupon',$d['scope'] ?? null,$d['line_index'] ?? null,$d['code'] ?? null,(int)$d['amount_cents'],json_encode($d, JSON_UNESCAPED_SLASHES)]);
        return $this->getById($orderId) ?? [];
    }

    public function clearCartItems(int $cartId): void { $this->db->execute('DELETE FROM cart_items WHERE cart_id=?', [$cartId]); }
    public function getById(int $id): ?array { return $this->one('SELECT * FROM orders WHERE id=? LIMIT 1', [$id]); }
    public function findByPublicKey(string $key): ?array { return $this->one('SELECT * FROM orders WHERE public_token=? LIMIT 1', [$key]); }
    public function publicItems(int $orderId): array
    {
        $items = $this->db->select('SELECT * FROM order_items WHERE order_id=? ORDER BY id', [$orderId]);
        foreach ($items as &$item) $item['modifiers'] = $this->db->select('SELECT * FROM order_item_modifiers WHERE order_item_id=? ORDER BY id', [(int)$item['id']]);
        return $items;
    }
    public function publicAddress(int $orderId): ?array { return $this->one('SELECT * FROM order_addresses WHERE order_id=? LIMIT 1', [$orderId]); }
    public function findByNumber(int $businessId, string $number): ?array { return $this->one('SELECT * FROM orders WHERE business_id=? AND number=? LIMIT 1', [$businessId, $number]); }
    public function markStatus(int $orderId, string $to): array { $o=$this->getById($orderId) ?? []; $status=OrderStateMap::create()->transition((string)$o['status'], $to); $this->db->execute('UPDATE orders SET status=? WHERE id=?', [$status,$orderId]); return $this->getById($orderId) ?? []; }
    public function markAcceptedIfPending(int $orderId): array { $this->db->execute("UPDATE orders SET status='accepted' WHERE id=? AND status='pending'", [$orderId]); return $this->getById($orderId) ?? []; }
    private function one(string $sql, array $p=[]): ?array { $r=$this->db->select($sql,$p); return $r[0] ?? null; }
}
