<?php declare(strict_types=1);
namespace VO\Inventory;

use DomainException; use InvalidArgumentException; use VO\Database\Connection;

final class InsufficientStockException extends DomainException {}

final class StockService
{
    private const RELEASE = ['release_cancelled','release_rejected','release_expired'];
    public function __construct(private Connection $db) {}

    public function reserve(int $businessId, int $branchId, int $orderId, array $items): void
    {
        foreach ($this->quantities($items) as $line) {
            $row = $this->branchItem($branchId, (int)$line['item_id']);
            if (!$row || $row['stock_mode'] !== 'simple') continue;
            $qty = (int)$line['quantity'];
            $ok = $this->db->execute('UPDATE branch_items SET reserved_quantity=reserved_quantity+? WHERE branch_id=? AND item_id=? AND stock_mode=? AND stock_quantity>=reserved_quantity+?', [$qty,$branchId,(int)$line['item_id'],'simple',$qty]);
            if ($ok !== 1) throw new InsufficientStockException('Insufficient stock for item ' . (int)$line['item_id']);
            $this->movement($businessId,$branchId,$orderId,(int)$line['item_id'],$line['variant_id'],$qty,'reserve','Order stock reservation');
        }
    }

    public function assertAvailable(int $branchId, array $items): void
    {
        foreach ($this->quantities($items) as $line) {
            $row = $this->branchItem($branchId, (int)$line['item_id']);
            if ($row && $row['stock_mode'] === 'simple' && (int)$row['stock_quantity'] - (int)$row['reserved_quantity'] < (int)$line['quantity']) throw new InsufficientStockException('Insufficient stock for item ' . (int)$line['item_id']);
        }
    }

    public function release(int $businessId, int $branchId, int $orderId, array $items, string $reason): void
    {
        if (!in_array($reason, self::RELEASE, true)) throw new InvalidArgumentException('Invalid stock release reason.');
        foreach ($this->quantities($items) as $line) {
            $row = $this->branchItem($branchId, (int)$line['item_id']);
            if (!$row || $row['stock_mode'] !== 'simple') continue;
            $qty = (int)$line['quantity'];
            $ok = $this->db->execute('UPDATE branch_items SET reserved_quantity=reserved_quantity-? WHERE branch_id=? AND item_id=? AND stock_mode=? AND reserved_quantity>=?', [$qty,$branchId,(int)$line['item_id'],'simple',$qty]);
            if ($ok === 1) $this->movement($businessId,$branchId,$orderId,(int)$line['item_id'],$line['variant_id'],-$qty,$reason,'Order stock release');
        }
    }

    private function branchItem(int $branchId, int $itemId): ?array { $r=$this->db->select('SELECT * FROM branch_items WHERE branch_id=? AND item_id=? LIMIT 1 FOR UPDATE', [$branchId,$itemId]); return $r[0] ?? null; }
    private function movement(int $businessId, int $branchId, int $orderId, int $itemId, ?int $variantId, int $qty, string $reason, string $note): void { $this->db->execute('INSERT INTO stock_movements (business_id,branch_id,order_id,item_id,variant_id,quantity_delta,reason,note,actor_type) VALUES (?,?,?,?,?,?,?,?,?)', [$businessId,$branchId,$orderId,$itemId,$variantId,$qty,$reason,$note,'system']); }
    private function quantities(array $items): array { $out=[]; foreach ($items as $i) { $k=(int)$i['item_id'] . ':' . ($i['variant_id'] ?? ''); $out[$k] ??= ['item_id'=>(int)$i['item_id'],'variant_id'=>$i['variant_id']===null?null:(int)$i['variant_id'],'quantity'=>0]; $out[$k]['quantity'] += (int)($i['qty'] ?? $i['quantity']); } return array_values($out); }
}
