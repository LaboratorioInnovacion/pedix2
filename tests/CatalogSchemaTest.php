<?php declare(strict_types=1);
namespace Tests;
use VO\Database\MigrationRunner;
use VO\Installer\InstallerSeeder;

final class CatalogSchemaTest extends TestCase
{
    public function testMigrationCreatesCatalogTablesConstraintsAndSeedsPermissionIdempotently(): void
    {
        $scratch = ScratchDatabase::create('vo_cat_test_'); if ($scratch === null) return;
        try {
            $runner = new MigrationRunner($scratch->connection(), dirname(__DIR__) . '/api/database/migrations');
            $this->assertSame(['001 create_baseline', '002 create_auth_runtime', '003 create_catalog', '004 create_pricing_promotions', '005 create_cart', '006 create_orders', '007 create_payments', '008 operations', '009 delivery', '010 notifications'], $runner->run());
            $this->assertSame([], $runner->run());
            // Real installs run the seeder AFTER migrations; it creates the owner
            // role and links the full permission set (incl. products.manage).
            (new InstallerSeeder($scratch->connection()))->seed([
                'business_name' => 'Cat Test', 'business_slug' => 'cat-test',
                'branch_name' => 'Main', 'branch_address' => '1 St', 'branch_phone' => '555',
                'timezone' => 'America/Argentina/Buenos_Aires',
                'admin_name' => 'Owner', 'admin_email' => 'owner@cat.test', 'admin_password' => 'change-me-now',
            ]);
            $tables = $scratch->tables();
            foreach (['categories','catalog_items','item_variants','modifier_groups','modifiers','item_modifier_group','branch_items','branch_variants','item_images'] as $table) $this->assertTrue(in_array($table, $tables, true), "$table missing");
            foreach (['cart_items','cart_item_modifiers'] as $table) $this->assertTrue(in_array($table, $tables, true), "$table missing");
            foreach (['payments','payment_events'] as $table) $this->assertTrue(in_array($table, $tables, true), "$table missing");
            foreach (['checkout','pricing_rules','reservations','uploads'] as $table) $this->assertTrue(!in_array($table, $tables, true), "$table must be deferred");
            $this->assertSame(1, (int) $scratch->pdo->query("SELECT COUNT(*) FROM permissions WHERE permission_key='products.manage'")->fetchColumn());
            $this->assertSame(1, (int) $scratch->pdo->query("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.name='owner' AND p.permission_key='products.manage'")->fetchColumn());
            $this->assertSame('InnoDB', $scratch->engine('catalog_items'));
            $this->assertSame('utf8mb4', $scratch->charset('catalog_items'));
            $scratch->pdo->exec("INSERT INTO categories (name,slug) VALUES ('Root','root')");
            $this->assertThrows(\Throwable::class, static fn () => $scratch->pdo->exec("INSERT INTO categories (name,slug) VALUES ('Root 2','root')"));
            $this->assertThrows(\Throwable::class, static fn () => $scratch->pdo->exec("INSERT INTO item_variants (item_id,name) VALUES (999,'Bad')"));
        } finally { $scratch->drop(); }
    }
}
