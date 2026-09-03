<?php declare(strict_types=1);
namespace Tests;

use PDO; use Throwable; use VO\Cart\CartRepository; use VO\Cart\CartService; use VO\Database\MigrationRunner; use VO\Delivery\DeliveryRepository; use VO\Domain\DbIdempotencyStore; use VO\Domain\InvalidTransition; use VO\Orders\EmptyCartException; use VO\Orders\OrderRepository; use VO\Orders\OrderService; use VO\Orders\OrderStateMap; use VO\Orders\PriceChangedException; use VO\Pricing\PricingRepository; use VO\Pricing\PricingService;

final class OrderCreationTest extends TestCase
{
    public function testHappyPathSnapshotsIdempotencyAndCartClearing(): void
    {
        $s = $this->db();
        try {
            [$svc, $cart, $pricing, $repo] = $this->services($s); $ids = $this->seedCatalog($s);
            $hash = hash('sha256', 'cart-a'); $cart->addToCart($hash, $ids['burger'], $ids['variant'], 2, [$ids['cheese']]);
            $cart->applyCoupon($hash, 'HALF'); $cart->setCheckoutData($hash, ['fulfillment'=>'delivery','branch_id'=>1,'payment_method'=>'cash','customer_note'=>'Leave at door','address_json'=>['recipient_name'=>'Ada','phone'=>'555','street'=>'Main 123','city'=>'CABA','notes'=>'Blue door']]);
            $accepted = $this->accepted($pricing, $s, $hash);

            $order = $svc->createFromCart($hash, 'idem-1', $accepted, ['name'=>'Ada','email'=>'ada@example.test','phone'=>'555']);
            $this->assertSame('B-000001', $order['number']); $this->assertSame('pending', $order['status']); $this->assertSame(64, strlen($order['public_token'])); $this->assertSame($accepted['grand_total_cents'], $order['grand_total_cents']);

            $row = $s->pdo->query('SELECT * FROM orders WHERE id=' . (int)$order['id'])->fetch(PDO::FETCH_ASSOC);
            foreach (OrderService::TOTAL_KEYS as $k) $this->assertSame($accepted[$k], (int)$row[$k], $k);
            $this->assertSame(null, $row['customer_id']); $this->assertSame('Ada', $row['customer_name']); $this->assertSame('ada@example.test', $row['customer_email']);
            $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM order_items WHERE order_id=' . (int)$order['id'] . " AND item_name='Burger' AND variant_name='Default'")->fetchColumn());
            $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM order_item_modifiers oim JOIN order_items oi ON oi.id=oim.order_item_id WHERE oi.order_id=' . (int)$order['id'] . " AND group_name='Toppings' AND modifier_name='Cheese'")->fetchColumn());
            $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM order_addresses WHERE order_id=' . (int)$order['id'] . " AND type='delivery' AND street='Main 123'")->fetchColumn());
            $this->assertSame('Zona Centro', $row['delivery_zone_name']); $this->assertSame(150, (int)$row['delivery_payout_cents']);
            $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM deliveries WHERE order_id=' . (int)$order['id'] . " AND state='pending'")->fetchColumn());
            $this->assertSame(2, (int)$s->pdo->query('SELECT COUNT(*) FROM order_discounts WHERE order_id=' . (int)$order['id'])->fetchColumn());
            $this->assertSame(0, (int)$s->pdo->query('SELECT COUNT(*) FROM cart_items')->fetchColumn()); $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM carts')->fetchColumn());
            $this->assertSame($accepted['grand_total_cents'], (int)$s->pdo->query('SELECT SUM(line_total_cents)+' . $accepted['delivery_fee_cents'] . ' FROM order_items WHERE order_id=' . (int)$order['id'])->fetchColumn());
            $this->assertSame((int)$order['id'], (int)$repo->findByPublicKey($order['public_token'])['id']); $this->assertSame((int)$order['id'], (int)$repo->findByNumber(1, 'B-000001')['id']);
            $this->assertSame('accepted', $repo->markStatus((int)$order['id'], 'accepted')['status']); $this->assertThrows(InvalidTransition::class, fn() => $repo->markStatus((int)$order['id'], 'completed'));

            $again = $svc->createFromCart($hash, 'idem-1', $accepted, ['name'=>'Ada','email'=>'ada@example.test','phone'=>'555']);
            $this->assertSame($order['id'], $again['id']); $this->assertSame($order['number'], $again['number']);
            $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
            $this->assertSame(1, (int)$s->pdo->query("SELECT times_used FROM coupons WHERE code='HALF'")->fetchColumn());
        } finally { $s->drop(); }
    }

    public function testPriceChangedEmptyCartCountersCustomerLinksAndStateMap(): void
    {
        $s = $this->db();
        try {
            [$svc, $cart, $pricing] = $this->services($s); $ids = $this->seedCatalog($s);
            $hash = hash('sha256', 'cart-b'); $cart->addToCart($hash, $ids['burger'], $ids['variant'], 1, []); $cart->setCheckoutData($hash, ['fulfillment'=>'pickup','branch_id'=>1]);
            $accepted = $this->accepted($pricing, $s, $hash); $stale = $accepted; $stale['grand_total_cents']++;
            try { $svc->createFromCart($hash, 'bad-price', $stale, ['name'=>'Ada']); throw new \RuntimeException('Expected price change.'); }
            catch (PriceChangedException $e) { $this->assertSame(true, isset($e->current['grand_total_cents'])); }
            $this->assertSame(0, (int)$s->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()); $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM cart_items')->fetchColumn()); $this->assertSame(0, (int)$s->pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn()); $this->assertSame(0, (int)$s->pdo->query('SELECT times_used FROM coupons WHERE code="HALF"')->fetchColumn());

            $empty = hash('sha256', 'empty'); $cart->get($empty); $this->assertThrows(EmptyCartException::class, fn() => $svc->createFromCart($empty, 'empty', ['grand_total_cents'=>0], []));

            $one = $svc->createFromCart($hash, 'ok-1', $accepted, ['name'=>'Ada']);
            $s->pdo->exec("INSERT INTO customers (business_id,name,email) VALUES (1,'Grace','grace@example.test')"); $customerId = (int)$s->pdo->lastInsertId();
            $hash2 = hash('sha256', 'cart-c'); $cart->addToCart($hash2, $ids['burger'], $ids['variant'], 1, []); $cart->setCheckoutData($hash2, ['fulfillment'=>'pickup','branch_id'=>1]);
            $two = $svc->createFromCart($hash2, 'ok-2', $this->accepted($pricing, $s, $hash2), ['customer_id'=>$customerId]);
            $this->assertSame('B-000001', $one['number']); $this->assertSame('B-000002', $two['number']);
            $this->assertSame($customerId, (int)$s->pdo->query('SELECT customer_id FROM orders WHERE id=' . (int)$two['id'])->fetchColumn());

            $sm = OrderStateMap::create(); $state = $sm->transition('pending','accepted'); $state = $sm->transition($state,'in_progress'); $state = $sm->transition($state,'ready'); $this->assertSame('completed', $sm->transition($state,'completed'));
            $this->assertThrows(InvalidTransition::class, fn() => $sm->transition('pending','completed'));
        } finally { $s->drop(); }
    }

    private function db(): ScratchDatabase { $s = ScratchDatabase::create('vo_orders7_test_'); (new MigrationRunner($s->connection(), dirname(__DIR__) . '/api/database/migrations'))->run(); $s->pdo->exec("INSERT INTO businesses (name,slug) VALUES ('Demo','demo')"); $s->pdo->exec("INSERT INTO branches (business_id,name,is_active) VALUES (1,'Main',1)"); return $s; }
    private function services(ScratchDatabase $s): array { $pricing = new PricingService(new PricingRepository($s->pdo)); $db = $s->connection(); $repo = new OrderRepository($db); return [new OrderService($db, $repo, new DbIdempotencyStore($db), $pricing), new CartService(new CartRepository($s->pdo), $pricing, new DeliveryRepository($s->connection())), $pricing, $repo]; }
    private function accepted(PricingService $pricing, ScratchDatabase $s, string $hash): array { $c = $s->pdo->query("SELECT * FROM carts WHERE token_hash=" . $s->pdo->quote($hash))->fetch(PDO::FETCH_ASSOC); $rows = $s->pdo->query('SELECT ci.*,GROUP_CONCAT(cim.modifier_id) mods FROM cart_items ci LEFT JOIN cart_item_modifiers cim ON cim.cart_item_id=ci.id WHERE ci.cart_id=' . (int)$c['id'] . ' GROUP BY ci.id ORDER BY ci.id')->fetchAll(PDO::FETCH_ASSOC); $q = $pricing->quote(['branch_id'=>(int)$c['branch_id'],'fulfillment'=>$c['fulfillment'],'coupon_code'=>$c['coupon_code'] ?? '','payment_method'=>$c['payment_method'] ?? '','delivery_fee_cents'=>$c['fulfillment']==='delivery'?(int)$c['delivery_fee_cents']:0,'items'=>array_map(fn($r)=>['item_id'=>(int)$r['item_id'],'variant_id'=>$r['variant_id']===null?null:(int)$r['variant_id'],'quantity'=>(int)$r['qty'],'modifier_ids'=>$r['mods'] ? array_map('intval', explode(',', $r['mods'])) : []], $rows)]); return $q + ['accepted_grand_total_cents'=>$q['grand_total_cents'],'accepted_lines'=>array_map(fn($l)=>['line_total_cents'=>$l['line_total_cents']], $q['lines'])]; }
    private function seedCatalog(ScratchDatabase $s): array { $s->pdo->exec("INSERT INTO branch_settings (branch_id,setting_key,setting_value) VALUES (1,'minimum_pickup_cents','0'),(1,'minimum_delivery_cents','0')"); $s->pdo->exec("INSERT INTO delivery_zones (branch_id,name,match_terms,customer_rate_cents,driver_payout_cents,is_active) VALUES (1,'Zona Centro','main, caba',300,150,1)"); $s->pdo->exec("INSERT INTO categories (id,name,slug) VALUES (1,'Food','food')"); $s->pdo->exec("INSERT INTO catalog_items (category_id,type,name,slug,base_price_cents,requires_variant,allows_delivery,is_active) VALUES (1,'product','Burger','burger',1000,0,1,1)"); $item=(int)$s->pdo->lastInsertId(); $s->pdo->exec("INSERT INTO item_variants (item_id,name,price_cents,is_active) VALUES ($item,'Default',1000,1)"); $variant=(int)$s->pdo->lastInsertId(); $s->pdo->exec("INSERT INTO branch_items (branch_id,item_id,is_available,stock_quantity) VALUES (1,$item,1,10)"); $s->pdo->exec("INSERT INTO branch_variants (branch_id,variant_id,is_available) VALUES (1,$variant,1)"); $s->pdo->exec("INSERT INTO modifier_groups (name,is_required,selection,min_select,max_select,is_active) VALUES ('Toppings',0,'multi',0,3,1)"); $group=(int)$s->pdo->lastInsertId(); $s->pdo->exec("INSERT INTO modifiers (group_id,name,price_delta_cents,is_active) VALUES ($group,'Cheese',100,1)"); $cheese=(int)$s->pdo->lastInsertId(); $s->pdo->exec("INSERT INTO item_modifier_group (item_id,group_id) VALUES ($item,$group)"); $s->pdo->exec("INSERT INTO promotions (name,type,priority,is_stackable) VALUES ('Ten off','min_amount_pct',1,1)"); $promo=(int)$s->pdo->lastInsertId(); $s->pdo->exec("INSERT INTO promotion_rules (promotion_id,scope,discount_basis_points,min_amount_cents) VALUES ($promo,'order',1000,100)"); $s->pdo->exec("INSERT INTO coupons (code,discount_basis_points,min_amount_cents,times_used) VALUES ('HALF',5000,100,0)"); return ['burger'=>$item,'variant'=>$variant,'cheese'=>$cheese]; }
}
