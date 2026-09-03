<?php declare(strict_types=1);
namespace Tests;

use PDO;
use Throwable;
use VO\Database\MigrationRunner;
use VO\Orders\OrderStateMap;

final class OrdersSchemaTest extends TestCase
{
    public function testFreshMigrationsCreateOrdersSchemaAndAreStable(): void
    {
        $scratch = ScratchDatabase::create('vo_orders7_test_'); if ($scratch === null) return;
        try {
            $runner = new MigrationRunner($scratch->connection(), dirname(__DIR__) . '/api/database/migrations');
            $this->assertSame(['001 create_baseline', '002 create_auth_runtime', '003 create_catalog', '004 create_pricing_promotions', '005 create_cart', '006 create_orders', '007 create_payments', '008 operations', '009 delivery', '010 notifications'], $runner->run());
            $this->assertSame([], $runner->run());
            $tables = $scratch->tables();
            foreach (['customers','order_counters','orders','order_items','order_item_modifiers','order_addresses','order_discounts','promotion_usage','stock_movements','idempotency_keys'] as $table) $this->assertTrue(in_array($table, $tables, true), "$table missing");
            foreach (['stock_quantity','reserved_quantity'] as $column) $this->assertTrue(in_array($column, $this->columns($scratch->pdo, 'branch_items'), true), "branch_items.$column missing");
            foreach (['business_id','branch_id','number','public_token','customer_id','status','cart_token_hash','fulfillment','payment_method','customer_name','customer_email','customer_phone','customer_note','gross_items_cents','item_promotions_cents','order_promotions_cents','coupon_discount_cents','payment_discount_cents','merchandise_total_cents','delivery_fee_cents','grand_total_cents','coupon_code','confirmed_at'] as $column) $this->assertTrue(in_array($column, $this->columns($scratch->pdo, 'orders'), true), "orders.$column missing");
        } finally { $scratch->drop(); }
    }

    public function testConstraintsIndexesAndForeignKeyBehavior(): void
    {
        $scratch = ScratchDatabase::create('vo_orders7_test_'); if ($scratch === null) return;
        try {
            (new MigrationRunner($scratch->connection(), dirname(__DIR__) . '/api/database/migrations'))->run();
            $pdo = $scratch->pdo;
            $this->seedOrderGraph($pdo);
            $this->expectThrows('duplicate customer email', static fn () => $pdo->exec("INSERT INTO customers (business_id,name,email,phone) VALUES (1,'C2','c@example.test','2')"));
            $this->expectThrows('duplicate order number per business', static fn () => $pdo->exec("INSERT INTO orders (business_id,branch_id,number,public_token,fulfillment,customer_name,gross_items_cents,item_promotions_cents,order_promotions_cents,coupon_discount_cents,payment_discount_cents,merchandise_total_cents,delivery_fee_cents,grand_total_cents) VALUES (1,1,'B-000001','" . str_repeat('b', 64) . "','pickup','Bad',1,0,0,0,0,1,0,1)"));
            $this->expectThrows('invalid order status', static fn () => $pdo->exec("INSERT INTO orders (business_id,branch_id,number,public_token,status,fulfillment,gross_items_cents,item_promotions_cents,order_promotions_cents,coupon_discount_cents,payment_discount_cents,merchandise_total_cents,delivery_fee_cents,grand_total_cents) VALUES (1,1,'B-000002','" . str_repeat('c', 64) . "','bogus','pickup',1,0,0,0,0,1,0,1)"));
            $this->expectThrows('invalid stock movement reason', static fn () => $pdo->exec("INSERT INTO stock_movements (business_id,branch_id,item_id,quantity_delta,reason) VALUES (1,1,1,1,'manual')"));
            $pdo->exec("INSERT INTO stock_movements (business_id,branch_id,item_id,quantity_delta,reason,order_id) VALUES (1,1,1,-1,'reserve',1)");
            $pdo->exec("INSERT INTO promotion_usage (order_id,business_id,promotion_id,kind,code,amount_cents) VALUES (1,1,1,'promotion','PROMO',100)");
            $this->expectThrows('duplicate promotion usage per order', static fn () => $pdo->exec("INSERT INTO promotion_usage (order_id,business_id,promotion_id,kind,code,amount_cents) VALUES (1,1,1,'promotion','PROMO',100)"));
            $pdo->exec("DELETE FROM orders WHERE id=1");
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM order_addresses')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM stock_movements WHERE order_id IS NULL')->fetchColumn());
        } finally { $scratch->drop(); }
    }

    public function testOrderStateMapAllowsOnlyDesignedTransitions(): void
    {
        $machine = OrderStateMap::create();
        foreach ([['pending','accepted'], ['pending','expired'], ['change_proposed','rejected'], ['accepted','in_progress'], ['in_progress','ready'], ['ready','completed']] as [$from, $to]) $this->assertTrue($machine->can($from, $to), "$from should transition to $to");
        foreach (['completed','rejected','cancelled','expired'] as $terminal) $this->assertTrue(!$machine->can($terminal, 'accepted'), "$terminal must be terminal");
        $this->assertTrue(!$machine->can('accepted', 'completed'));
    }

    private function seedOrderGraph(PDO $pdo): void
    {
        $pdo->exec("INSERT INTO businesses (id,name,slug) VALUES (1,'Demo','demo'),(2,'Other','other')");
        $pdo->exec("INSERT INTO branches (id,business_id,name) VALUES (1,1,'Main')");
        $pdo->exec("INSERT INTO categories (id,name,slug) VALUES (1,'Root','root')");
        $pdo->exec("INSERT INTO catalog_items (id,category_id,type,name,slug,base_price_cents) VALUES (1,1,'product','Pizza','pizza',1000)");
        $pdo->exec("INSERT INTO item_variants (id,item_id,name,price_cents) VALUES (1,1,'Large',1200)");
        $pdo->exec("INSERT INTO modifier_groups (id,name) VALUES (1,'Extras')");
        $pdo->exec("INSERT INTO modifiers (id,group_id,name,price_delta_cents) VALUES (1,1,'Cheese',100)");
        $pdo->exec("INSERT INTO promotions (id,name,type) VALUES (1,'Promo','min_amount_pct')");
        $pdo->exec("INSERT INTO coupons (id,code,discount_basis_points) VALUES (1,'SAVE',1000)");
        $pdo->exec("INSERT INTO customers (id,business_id,name,email,phone) VALUES (1,1,'C','c@example.test','1'),(2,2,'C','c@example.test','1')");
        $pdo->exec("INSERT INTO order_counters (business_id,next_number) VALUES (1,2)");
        $pdo->exec("INSERT INTO orders (id,business_id,branch_id,customer_id,number,public_token,fulfillment,customer_name,customer_email,customer_phone,gross_items_cents,item_promotions_cents,order_promotions_cents,coupon_discount_cents,payment_discount_cents,merchandise_total_cents,delivery_fee_cents,grand_total_cents) VALUES (1,1,1,1,'B-000001','" . str_repeat('a', 64) . "','pickup','C','c@example.test','1',1200,0,0,0,0,1200,0,1200)");
        $pdo->exec("INSERT INTO order_items (id,order_id,branch_id,item_id,variant_id,item_name,variant_name,quantity,unit_price_cents,modifiers_total_cents,gross_unit_cents,gross_line_cents,line_total_cents) VALUES (1,1,1,1,1,'Pizza','Large',1,1200,100,1300,1300,1300)");
        $pdo->exec("INSERT INTO order_item_modifiers (order_item_id,modifier_id,group_name,modifier_name,price_delta_cents,qty) VALUES (1,1,'Extras','Cheese',100,1)");
        $pdo->exec("INSERT INTO order_addresses (order_id,type,recipient_name,phone,street,city,notes,address_json) VALUES (1,'delivery','C','1','Street','City','Note',JSON_OBJECT('n',1))");
    }

    private function columns(PDO $pdo, string $table): array { return array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC), 'Field'); }
    private function expectThrows(string $label, callable $fn): void { try { $fn(); } catch (Throwable) { return; } throw new \RuntimeException($label . ' should fail'); }
}
