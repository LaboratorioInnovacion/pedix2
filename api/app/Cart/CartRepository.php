<?php declare(strict_types=1);
namespace VO\Cart;

use PDO;

final class CartRepository
{
    public function __construct(private PDO $pdo) {}
    public function create(string $hash): array { $this->exec('INSERT INTO carts (token_hash,expires_at) VALUES (?,?)', [$hash, CartToken::expiresAt()]); return $this->findByTokenHash($hash) ?? []; }
    public function findByTokenHash(string $hash): ?array { return $this->one('SELECT * FROM carts WHERE token_hash=? LIMIT 1', [$hash]); }
    public function findOrCreateByTokenHash(string $hash): array { $c = $this->findByTokenHash($hash); if ($c && $this->expired($c)) { $this->removeCart((int)$c['id']); $c = null; } return $c ?: $this->create($hash); }
    public function clearExpiredCart(string $hash): bool { $c = $this->findByTokenHash($hash); if (!$c || !$this->expired($c)) return false; $this->removeCart((int)$c['id']); return true; }
    public function deleteExpiredCarts(): int { return $this->exec('DELETE FROM carts WHERE expires_at IS NOT NULL AND expires_at<NOW()'); }
    public function touch(int $cartId): void { $this->exec('UPDATE carts SET expires_at=? WHERE id=?', [CartToken::expiresAt(), $cartId]); }
    public function updateCart(int $id, array $d): void { $this->exec('UPDATE carts SET branch_id=?,fulfillment=?,coupon_code=?,payment_method=?,address_json=?,customer_note=?,delivery_zone_id=?,delivery_fee_cents=?,delivery_payout_cents=?,expires_at=? WHERE id=?', [$d['branch_id'],$d['fulfillment'],$d['coupon_code'],$d['payment_method'],$d['address_json'],$d['customer_note'],$d['delivery_zone_id'] ?? null,(int)($d['delivery_fee_cents'] ?? 0),(int)($d['delivery_payout_cents'] ?? 0),CartToken::expiresAt(),$id]); }
    public function upsertItem(int $cartId, int $itemId, ?int $variantId, int $qty, array $modifierIds): int { $this->exec('INSERT INTO cart_items (cart_id,item_id,variant_id,qty) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE qty=VALUES(qty),updated_at=CURRENT_TIMESTAMP', [$cartId,$itemId,$variantId,$qty]); $id=(int)($this->one('SELECT id FROM cart_items WHERE cart_id=? AND item_id=? AND ' . ($variantId===null?'variant_id IS NULL':'variant_id=?') . ' LIMIT 1', $variantId===null?[$cartId,$itemId]:[$cartId,$itemId,$variantId])['id'] ?? 0); $this->exec('DELETE FROM cart_item_modifiers WHERE cart_item_id=?', [$id]); foreach ($modifierIds as $m) $this->exec('INSERT INTO cart_item_modifiers (cart_item_id,modifier_id,qty) VALUES (?,?,1)', [$id,$m]); $this->touch($cartId); return $id; }
    public function updateQty(int $cartId, int $lineId, int $qty): bool { $n=$this->exec('UPDATE cart_items SET qty=? WHERE id=? AND cart_id=?', [$qty,$lineId,$cartId]); if ($n) $this->touch($cartId); return $n>0; }
    public function removeItem(int $cartId, int $lineId): bool { $n=$this->exec('DELETE FROM cart_items WHERE id=? AND cart_id=?', [$lineId,$cartId]); if ($n) $this->touch($cartId); return $n>0; }
    public function items(int $cartId): array { $rows=$this->many('SELECT ci.*,i.type,i.name,i.is_active item_active,i.archived_at item_archived,v.is_active variant_active,v.archived_at variant_archived FROM cart_items ci JOIN catalog_items i ON i.id=ci.item_id LEFT JOIN item_variants v ON v.id=ci.variant_id WHERE ci.cart_id=? ORDER BY ci.id', [$cartId]); foreach ($rows as &$r) $r['modifiers']=$this->many('SELECT m.* FROM cart_item_modifiers cim JOIN modifiers m ON m.id=cim.modifier_id WHERE cim.cart_item_id=? ORDER BY cim.id', [(int)$r['id']]); return $rows; }
    public function item(int $itemId): ?array { return $this->one('SELECT * FROM catalog_items WHERE id=? LIMIT 1', [$itemId]); }
    public function variant(int $itemId, int $variantId): ?array { return $this->one('SELECT * FROM item_variants WHERE id=? AND item_id=? AND is_active=1 AND archived_at IS NULL LIMIT 1', [$variantId,$itemId]); }
    public function branchForItem(int $itemId, ?int $variantId): ?int { $row=$this->one('SELECT bi.branch_id FROM branch_items bi JOIN branches b ON b.id=bi.branch_id WHERE bi.item_id=? AND bi.is_available=1 AND b.is_active=1 ORDER BY bi.branch_id LIMIT 1', [$itemId]); if (!$row) return null; if ($variantId!==null && !$this->one('SELECT 1 FROM branch_variants WHERE branch_id=? AND variant_id=? AND is_available=1', [(int)$row['branch_id'],$variantId])) return null; return (int)$row['branch_id']; }
    public function groups(int $itemId): array { return $this->many('SELECT g.* FROM modifier_groups g JOIN item_modifier_group l ON l.group_id=g.id WHERE l.item_id=? AND g.is_active=1 AND g.archived_at IS NULL', [$itemId]); }
    public function modifiers(array $ids): array { if (!$ids) return []; return $this->many('SELECT m.*,g.min_select,g.max_select,g.id group_id FROM modifiers m JOIN modifier_groups g ON g.id=m.group_id WHERE m.is_active=1 AND m.archived_at IS NULL AND m.id IN (' . rtrim(str_repeat('?,', count($ids)), ',') . ')', $ids); }
    public function coupon(string $code): ?array { return $this->one("SELECT * FROM coupons WHERE UPPER(code)=UPPER(?) AND archived_at IS NULL AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) AND (usage_limit IS NULL OR times_used<usage_limit) LIMIT 1", [$code]); }
    private function expired(array $c): bool { return !empty($c['expires_at']) && strtotime((string)$c['expires_at']) < time(); }
    private function removeCart(int $id): void { $this->exec('DELETE FROM carts WHERE id=?', [$id]); }
    private function many(string $sql, array $p=[]): array { $s=$this->pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function one(string $sql, array $p=[]): ?array { $r=$this->many($sql,$p); return $r[0]??null; }
    private function exec(string $sql, array $p=[]): int { $s=$this->pdo->prepare($sql); $s->execute($p); return $s->rowCount(); }
}
