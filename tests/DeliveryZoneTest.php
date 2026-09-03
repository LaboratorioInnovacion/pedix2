<?php declare(strict_types=1);
namespace Tests;

use PDO; use VO\Cart\CartRepository; use VO\Cart\CartService; use VO\Cart\CartValidationException; use VO\Database\MigrationRunner; use VO\Delivery\DeliveryRepository; use VO\Delivery\DeliveryUnavailableException; use VO\Delivery\ZoneMatcher; use VO\Domain\DbIdempotencyStore; use VO\Orders\OrderRepository; use VO\Orders\OrderService; use VO\Orders\PriceChangedException; use VO\Pricing\PricingRepository; use VO\Pricing\PricingService;

final class DeliveryZoneTest extends TestCase
{
    public function testMatcherNormalizationTermSplittingAndSubstring(): void
    {
        $this->assertSame('arbol cafe nandu', ZoneMatcher::normalize("  Árbol Café ÑANDÚ "));
        $this->assertSame('av siempre 1234 caba', ZoneMatcher::normalize('Av Siempre 1234 CABA'));
        $this->assertSame(['palermo', 'colegiales', 'norte'], ZoneMatcher::terms('Palermo, COLEGIALES/Norte ,'));
        $this->assertSame([], ZoneMatcher::terms(' , / '));
        $this->assertTrue(ZoneMatcher::matches(ZoneMatcher::normalize('Av Siempre 1234 CABA'), ZoneMatcher::terms('siempre, norte')));
        $this->assertTrue(ZoneMatcher::matches(ZoneMatcher::normalize('CALLE FALSA 123'), ZoneMatcher::terms('falsa')));
        $this->assertTrue(!ZoneMatcher::matches(ZoneMatcher::normalize('Calle Falsa 123'), ZoneMatcher::terms('siempre')));
        $this->assertTrue(!ZoneMatcher::matches(ZoneMatcher::normalize('Calle Falsa 123'), []));
    }

    public function testFirstActiveZoneByIdWinsAndNoMatchIsNull(): void
    {
        $s = $this->db();
        try {
            $repo = new DeliveryRepository($s->connection());
            $z1 = $this->zone($s, 1, 'Zona Uno', 'siempre', 300, 150); // lower id, matches too
            $z2 = $this->zone($s, 1, 'Zona Dos', 'siempre, caba', 500, 200);
            $hit = $repo->matchZone(1, 'Av Siempre 1234', 'CABA');
            $this->assertSame($z1, (int)$hit['id']);
            $s->pdo->exec('UPDATE delivery_zones SET is_active=0 WHERE id=' . $z1);
            $this->assertSame($z2, (int)($repo->matchZone(1, 'Av Siempre 1234', 'CABA')['id'] ?? 0));
            $this->assertSame(null, $repo->matchZone(1, 'Calle Falsa 123', 'Prov'));
            $this->assertSame(null, $repo->matchZone(1, null, null));
            // Zones of another branch never match this address.
            $this->assertSame(null, $repo->matchZone(999, 'Av Siempre 1234', 'CABA'));
        } finally { $s->drop(); }
    }

    public function testMigration009SchemaPins(): void
    {
        $s = $this->db();
        try {
            $tables = $s->tables();
            foreach (['delivery_zones','delivery_persons','delivery_person_branches','deliveries'] as $t) $this->assertTrue(in_array($t, $tables, true), "$t missing");
            foreach (['delivery_zone_id','delivery_fee_cents','delivery_payout_cents'] as $c) $this->assertTrue(in_array($c, $this->columns($s->pdo, 'carts'), true), "carts.$c missing");
            foreach (['delivery_zone_name','delivery_payout_cents'] as $c) $this->assertTrue(in_array($c, $this->columns($s->pdo, 'orders'), true), "orders.$c missing");
            foreach (['state','pin_hash','pin_attempts','failed_reason','delivery_person_id'] as $c) $this->assertTrue(in_array($c, $this->columns($s->pdo, 'deliveries'), true), "deliveries.$c missing");
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM permissions WHERE permission_key='deliveries.manage'")->fetchColumn());
            // Fresh installs: no owner role exists at migration time, so the migration rp seed binds nothing;
            // InstallerSeeder binds deliveries.manage to owner (asserted in InstallerSeederTest). Upgrades with an
            // existing owner role are bound by the migration's WHERE NOT EXISTS seed.
            $this->assertSame(0, (int)$s->pdo->query("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.name='owner' AND p.permission_key='deliveries.manage'")->fetchColumn());
            // pin_key is provisioned lazily per business (InstallerSeeder creates businesses AFTER migrations), not by migration-time seed on empty installs.
            $this->assertSame(0, (int)$s->pdo->query("SELECT COUNT(*) FROM business_settings WHERE setting_key='delivery.pin_key'")->fetchColumn());
            $key = (new DeliveryRepository($s->connection()))->ensurePinKey(1);
            $this->assertTrue($key !== '', 'ensurePinKey returned empty key');
            $this->assertSame(1, (int)$s->pdo->query("SELECT COUNT(*) FROM business_settings WHERE setting_key='delivery.pin_key' AND setting_value<>''")->fetchColumn());
            $this->assertSame($key, (new DeliveryRepository($s->connection()))->ensurePinKey(1)); // idempotent
            $this->assertSame([], (new MigrationRunner($s->connection(), dirname(__DIR__) . '/api/database/migrations'))->run());
        } finally { $s->drop(); }
    }

    public function testDeliveryCheckoutResolvesZoneFeePayoutAndQuotesIt(): void
    {
        $s = $this->db();
        try {
            [$cart] = $this->services($s); $ids = $this->seedCatalog($s); $zoneId = $this->zone($s, 1, 'Zona Centro', 'main, caba', 300, 150);
            $hash = hash('sha256', 'del-cart-a'); $cart->addToCart($hash, $ids['burger'], $ids['variant'], 2, []);
            $state = $cart->setCheckoutData($hash, ['fulfillment'=>'delivery','branch_id'=>1,'payment_method'=>'cash','customer_note'=>'Tocar timbre','address_json'=>['street'=>'Main 123','city'=>'CABA']]);
            $row = $s->pdo->query('SELECT * FROM carts WHERE token_hash=' . $s->pdo->quote($hash))->fetch(PDO::FETCH_ASSOC);
            $this->assertSame($zoneId, (int)$row['delivery_zone_id']); $this->assertSame(300, (int)$row['delivery_fee_cents']); $this->assertSame(150, (int)$row['delivery_payout_cents']);
            $this->assertSame('delivery', $state['cart']['fulfillment']);
            $this->assertSame(300, (int)$state['pricing']['delivery_fee_cents']);
            $this->assertSame(2300, (int)$state['pricing']['grand_total_cents']); // 2 x 1000 + 300 fee
            $this->assertSame(2000, (int)$state['pricing']['merchandise_total_cents']);
        } finally { $s->drop(); }
    }

    public function testNoMatchThrowsTypedSpanishErrorAndKeepsPriorState(): void
    {
        $s = $this->db();
        try {
            [$cart] = $this->services($s); $ids = $this->seedCatalog($s); $this->zone($s, 1, 'Zona Centro', 'main, caba', 300, 150);
            $hash = hash('sha256', 'del-cart-b'); $cart->addToCart($hash, $ids['burger'], $ids['variant'], 1, []);
            $cart->setCheckoutData($hash, ['fulfillment'=>'pickup','branch_id'=>1]);
            try {
                $cart->setCheckoutData($hash, ['fulfillment'=>'delivery','branch_id'=>1,'address_json'=>['street'=>'Calle Falsa 123','city'=>'Prov']]);
                throw new \RuntimeException('Expected DeliveryUnavailableException.');
            } catch (DeliveryUnavailableException $e) {
                $this->assertSame('No tenemos cobertura de envío para esa dirección.', $e->getMessage());
                $this->assertSame(422, $e->status);
                $this->assertTrue($e instanceof CartValidationException);
            }
            $row = $s->pdo->query('SELECT * FROM carts WHERE token_hash=' . $s->pdo->quote($hash))->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('pickup', $row['fulfillment']); $this->assertSame(null, $row['delivery_zone_id']); $this->assertSame(0, (int)$row['delivery_fee_cents']); $this->assertSame(0, (int)$row['delivery_payout_cents']);
            // Delivery without any address is also uncovered.
            $this->assertThrows(DeliveryUnavailableException::class, fn() => $cart->setCheckoutData($hash, ['fulfillment'=>'delivery','branch_id'=>1]));
        } finally { $s->drop(); }
    }

    public function testPickupCheckoutZeroesDeliveryState(): void
    {
        $s = $this->db();
        try {
            [$cart] = $this->services($s); $ids = $this->seedCatalog($s); $this->zone($s, 1, 'Zona Centro', 'main, caba', 300, 150);
            $hash = hash('sha256', 'del-cart-c'); $cart->addToCart($hash, $ids['burger'], $ids['variant'], 1, []);
            $cart->setCheckoutData($hash, ['fulfillment'=>'delivery','branch_id'=>1,'address_json'=>['street'=>'Main 123','city'=>'CABA']]);
            $row = $s->pdo->query('SELECT * FROM carts WHERE token_hash=' . $s->pdo->quote($hash))->fetch(PDO::FETCH_ASSOC);
            $this->assertSame(300, (int)$row['delivery_fee_cents']);
            $state = $cart->setCheckoutData($hash, ['fulfillment'=>'pickup','branch_id'=>1]);
            $row = $s->pdo->query('SELECT * FROM carts WHERE token_hash=' . $s->pdo->quote($hash))->fetch(PDO::FETCH_ASSOC);
            $this->assertSame(null, $row['delivery_zone_id']); $this->assertSame(0, (int)$row['delivery_fee_cents']); $this->assertSame(0, (int)$row['delivery_payout_cents']);
            $this->assertSame(0, (int)$state['pricing']['delivery_fee_cents']);
        } finally { $s->drop(); }
    }

    public function testOrderCreationForcesCartFeeAndSnapshotsZone(): void
    {
        $s = $this->db();
        try {
            [$cart, $svc] = $this->services($s); $ids = $this->seedCatalog($s); $this->zone($s, 1, 'Zona Centro', 'main, caba', 300, 150);
            $hash = hash('sha256', 'del-order'); $cart->addToCart($hash, $ids['burger'], $ids['variant'], 2, []);
            $cart->setCheckoutData($hash, ['fulfillment'=>'delivery','branch_id'=>1,'address_json'=>['street'=>'Main 123','city'=>'CABA']]);
            $accepted = $this->accepted($s, $hash);
            $this->assertSame(300, (int)$accepted['delivery_fee_cents']); $this->assertSame(2300, (int)$accepted['grand_total_cents']);
            // Client lies about the fee; the server forces the cart-stored value.
            $lying = $accepted; $lying['delivery_fee_cents'] = 999999;
            $order = $svc->createFromCart($hash, 'idem-del-1', $lying, ['name'=>'Ada']);
            $row = $s->pdo->query('SELECT * FROM orders WHERE id=' . (int)$order['id'])->fetch(PDO::FETCH_ASSOC);
            $this->assertSame(300, (int)$row['delivery_fee_cents']);
            $this->assertSame(2300, (int)$row['grand_total_cents']);
            $this->assertSame('Zona Centro', $row['delivery_zone_name']); $this->assertSame(150, (int)$row['delivery_payout_cents']);
            $this->assertSame(1, (int)$s->pdo->query('SELECT COUNT(*) FROM deliveries WHERE order_id=' . (int)$order['id'] . " AND state='pending'")->fetchColumn());
            // PRICE_CHANGED needs a FRESH cart: the first creation already cleared $hash (by design).
            $hash2 = hash('sha256', 'del-order-2'); $cart->addToCart($hash2, $ids['burger'], $ids['variant'], 1, []);
            $cart->setCheckoutData($hash2, ['fulfillment'=>'delivery','branch_id'=>1,'address_json'=>['street'=>'Main 123','city'=>'CABA']]);
            $acc2 = $this->accepted($s, $hash2); // 1000 + 300 fee = 1300
            $this->assertSame(1300, (int)$acc2['grand_total_cents']);
            $stale = $acc2; $stale['delivery_fee_cents'] = 999999; $stale['grand_total_cents'] = 1000 + 999999;
            try { $svc->createFromCart($hash2, 'idem-del-2', $stale, ['name'=>'Ada']); throw new \RuntimeException('Expected price change.'); }
            catch (PriceChangedException $e) { $this->assertSame(300, (int)$e->current['delivery_fee_cents']); }
        } finally { $s->drop(); }
    }

    // --- helpers ---

    private function db(): ScratchDatabase { $s = ScratchDatabase::create('vo_del10_test_'); (new MigrationRunner($s->connection(), dirname(__DIR__) . '/api/database/migrations'))->run(); $s->pdo->exec("INSERT INTO businesses (name,slug) VALUES ('Demo','demo')"); $s->pdo->exec("INSERT INTO branches (business_id,name,is_active) VALUES (1,'Main',1)"); return $s; }
    private function services(ScratchDatabase $s): array { $pricing = new PricingService(new PricingRepository($s->pdo)); return [new CartService(new CartRepository($s->pdo), $pricing, new DeliveryRepository($s->connection())), new OrderService($s->connection(), new OrderRepository($s->connection()), new DbIdempotencyStore($s->connection()), $pricing)]; }
    private function zone(ScratchDatabase $s, int $branchId, string $name, string $terms, int $fee, int $payout): int { $s->pdo->exec("INSERT INTO delivery_zones (branch_id,name,match_terms,customer_rate_cents,driver_payout_cents,is_active) VALUES ($branchId," . $s->pdo->quote($name) . ',' . $s->pdo->quote($terms) . ",$fee,$payout,1)"); return (int)$s->pdo->lastInsertId(); }
    private function seedCatalog(ScratchDatabase $s): array { $s->pdo->exec("INSERT INTO categories (id,name,slug) VALUES (1,'Food','food')"); $s->pdo->exec("INSERT INTO catalog_items (category_id,type,name,slug,base_price_cents,requires_variant,allows_delivery,is_active) VALUES (1,'product','Burger','burger',1000,0,1,1)"); $item=(int)$s->pdo->lastInsertId(); $s->pdo->exec("INSERT INTO item_variants (item_id,name,price_cents,is_active) VALUES ($item,'Default',1000,1)"); $variant=(int)$s->pdo->lastInsertId(); $s->pdo->exec("INSERT INTO branch_items (branch_id,item_id,is_available,stock_quantity) VALUES (1,$item,1,10)"); $s->pdo->exec("INSERT INTO branch_variants (branch_id,variant_id,is_available) VALUES (1,$variant,1)"); return ['burger'=>$item,'variant'=>$variant]; }
    private function accepted(ScratchDatabase $s, string $hash): array { $pricing = new PricingService(new PricingRepository($s->pdo)); $c = $s->pdo->query('SELECT * FROM carts WHERE token_hash=' . $s->pdo->quote($hash))->fetch(PDO::FETCH_ASSOC); $rows = $s->pdo->query('SELECT ci.*,GROUP_CONCAT(cim.modifier_id) mods FROM cart_items ci LEFT JOIN cart_item_modifiers cim ON cim.cart_item_id=ci.id WHERE ci.cart_id=' . (int)$c['id'] . ' GROUP BY ci.id ORDER BY ci.id')->fetchAll(PDO::FETCH_ASSOC); $q = $pricing->quote(['branch_id'=>(int)$c['branch_id'],'fulfillment'=>$c['fulfillment'],'coupon_code'=>$c['coupon_code'] ?? '','payment_method'=>$c['payment_method'] ?? '','delivery_fee_cents'=>$c['fulfillment']==='delivery'?(int)$c['delivery_fee_cents']:0,'items'=>array_map(fn($r)=>['item_id'=>(int)$r['item_id'],'variant_id'=>$r['variant_id']===null?null:(int)$r['variant_id'],'quantity'=>(int)$r['qty'],'modifier_ids'=>$r['mods'] ? array_map('intval', explode(',', $r['mods'])) : []], $rows)]); return $q + ['accepted_grand_total_cents'=>$q['grand_total_cents'],'accepted_lines'=>array_map(fn($l)=>['line_total_cents'=>$l['line_total_cents']], $q['lines'])]; }
    private function columns(PDO $pdo, string $table): array { return array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC), 'Field'); }
}
