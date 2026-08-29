<?php declare(strict_types=1);
namespace Tests;

use PDO; use VO\Cart\CartRepository; use VO\Cart\CartService; use VO\Database\MigrationRunner; use VO\Domain\DbIdempotencyStore; use VO\Inventory\InsufficientStockException; use VO\Orders\OrderRepository; use VO\Orders\OrderService; use VO\Pricing\PricingRepository; use VO\Pricing\PricingService;

final class StockMovementTest extends TestCase
{
    public function testSimpleStockIsReservedAndMovementIsLogged(): void
    {
        $s = $this->db();
        try { [$svc,$cart,$pricing] = $this->services($s); $ids = $this->seed($s, 'simple', 5, 1); $hash = hash('sha256','stock-a'); $cart->addToCart($hash,$ids['item'],$ids['variant'],3,[]); $cart->setCheckoutData($hash,['fulfillment'=>'pickup','branch_id'=>1]); $o = $svc->createFromCart($hash,'stock-ok',$this->accepted($pricing,$s,$hash),['name'=>'Ada']);
            $bi = $s->pdo->query('SELECT stock_quantity,reserved_quantity FROM branch_items WHERE item_id=' . $ids['item'])->fetch(PDO::FETCH_ASSOC); $this->assertSame(5,(int)$bi['stock_quantity']); $this->assertSame(4,(int)$bi['reserved_quantity']);
            $m = $s->pdo->query('SELECT * FROM stock_movements WHERE order_id=' . (int)$o['id'])->fetch(PDO::FETCH_ASSOC); $this->assertSame('reserve',$m['reason']); $this->assertSame(3,(int)$m['quantity_delta']); $this->assertSame($ids['item'],(int)$m['item_id']);
        } finally { $s->drop(); }
    }

    public function testUnlimitedStockSkipsReservationAndInsufficientStockRollsBack(): void
    {
        $s = $this->db();
        try { [$svc,$cart,$pricing] = $this->services($s); $ids = $this->seed($s, 'unlimited', 0, 0); $h = hash('sha256','stock-b'); $cart->addToCart($h,$ids['item'],$ids['variant'],9,[]); $cart->setCheckoutData($h,['fulfillment'=>'pickup','branch_id'=>1]); $svc->createFromCart($h,'unlimited',$this->accepted($pricing,$s,$h),[]); $this->assertSame(0,(int)$s->pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn()); $this->assertSame(0,(int)$s->pdo->query('SELECT reserved_quantity FROM branch_items WHERE item_id=' . $ids['item'])->fetchColumn());
            $ids2 = $this->seed($s, 'simple', 2, 0, 'short'); $h2 = hash('sha256','stock-c'); $cart->addToCart($h2,$ids2['item'],$ids2['variant'],3,[]); $cart->setCheckoutData($h2,['fulfillment'=>'pickup','branch_id'=>1]); $this->assertThrows(InsufficientStockException::class, fn() => $svc->createFromCart($h2,'short',$this->accepted($pricing,$s,$h2),[])); $this->assertSame(1,(int)$s->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()); $this->assertSame(0,(int)$s->pdo->query('SELECT COUNT(*) FROM stock_movements WHERE item_id=' . $ids2['item'])->fetchColumn());
        } finally { $s->drop(); }
    }

    private function db(): ScratchDatabase { $s = ScratchDatabase::create('vo_orders7_test_'); (new MigrationRunner($s->connection(), dirname(__DIR__) . '/api/database/migrations'))->run(); $s->pdo->exec("INSERT INTO businesses (name,slug) VALUES ('Demo','demo')"); $s->pdo->exec("INSERT INTO branches (business_id,name,is_active) VALUES (1,'Main',1)"); $s->pdo->exec("INSERT INTO branch_settings (branch_id,setting_key,setting_value) VALUES (1,'minimum_pickup_cents','0')"); return $s; }
    private function services(ScratchDatabase $s): array { $pricing = new PricingService(new PricingRepository($s->pdo)); $db = $s->connection(); $repo = new OrderRepository($db); return [new OrderService($db, $repo, new DbIdempotencyStore($db), $pricing), new CartService(new CartRepository($s->pdo), $pricing), $pricing]; }
    private function seed(ScratchDatabase $s, string $mode, int $stock, int $reserved, string $slug='burger'): array { $s->pdo->exec("INSERT IGNORE INTO categories (id,name,slug) VALUES (1,'Food','food')"); $s->pdo->exec("INSERT INTO catalog_items (category_id,type,name,slug,base_price_cents,requires_variant,allows_delivery,is_active) VALUES (1,'product','$slug','$slug',1000,0,1,1)"); $item=(int)$s->pdo->lastInsertId(); $s->pdo->exec("INSERT INTO item_variants (item_id,name,price_cents,is_active) VALUES ($item,'Default',1000,1)"); $variant=(int)$s->pdo->lastInsertId(); $s->pdo->exec("INSERT INTO branch_items (branch_id,item_id,is_available,stock_mode,stock_quantity,reserved_quantity) VALUES (1,$item,1,'$mode',$stock,$reserved)"); $s->pdo->exec("INSERT INTO branch_variants (branch_id,variant_id,is_available) VALUES (1,$variant,1)"); return ['item'=>$item,'variant'=>$variant]; }
    private function accepted(PricingService $pricing, ScratchDatabase $s, string $hash): array { $c=$s->pdo->query("SELECT * FROM carts WHERE token_hash=".$s->pdo->quote($hash))->fetch(PDO::FETCH_ASSOC); $rows=$s->pdo->query('SELECT * FROM cart_items WHERE cart_id='.(int)$c['id'].' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC); $q=$pricing->quote(['branch_id'=>(int)$c['branch_id'],'fulfillment'=>$c['fulfillment'],'items'=>array_map(fn($r)=>['item_id'=>(int)$r['item_id'],'variant_id'=>(int)$r['variant_id'],'quantity'=>(int)$r['qty']],$rows)]); return $q + ['accepted_grand_total_cents'=>$q['grand_total_cents'],'accepted_lines'=>array_map(fn($l)=>['line_total_cents'=>$l['line_total_cents']],$q['lines'])]; }
}
