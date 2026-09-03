<?php declare(strict_types=1);
namespace Tests;
use PDO; use Throwable; use VO\Database\MigrationRunner; use VO\Database\PdoConnection;

final class BaselineSchemaTest extends TestCase
{
    public function testFreshMigrationCreatesBaselineOnlyAndRerunIsStable(): void
    {
        $scratch = ScratchDatabase::create(); if ($scratch === null) return;
        try {
            $runner = new MigrationRunner($scratch->connection(), dirname(__DIR__) . '/api/database/migrations');
            $this->assertSame(['001 create_baseline', '002 create_auth_runtime', '003 create_catalog', '004 create_pricing_promotions', '005 create_cart', '006 create_orders', '007 create_payments', '008 operations', '009 delivery', '010 notifications'], $runner->run());
            $this->assertSame([], $runner->run());
            $tables = $scratch->tables(); sort($tables);
            $expected = ['audit_log','auth_sessions','branches','branch_settings','branch_items','branch_variants','businesses','business_settings','cart_item_modifiers','cart_items','carts','catalog_items','categories','coupons','customers','delivery_person_branches','delivery_persons','delivery_zones','deliveries','idempotency_keys','item_images','item_modifier_group','item_variants','login_attempts','modifier_groups','modifiers','notification_events','order_addresses','order_counters','order_discounts','order_item_modifiers','order_items','orders','payment_events','payments','permissions','promotion_rules','promotion_usage','promotions','roles','role_permissions','schema_migrations','stock_movements','users','user_branches','user_roles']; sort($expected);
            $this->assertSame($expected, $tables);
            $version = $scratch->pdo->query('SELECT version FROM schema_migrations')->fetchColumn();
            $this->assertSame('001', $version);
            foreach (['catalog','sessions','modules','backups','workers'] as $deferred) $this->assertTrue(!in_array($deferred, $tables, true), $deferred . ' must be absent.');
        } finally { $scratch->drop(); }
    }

    public function testConstraintsRejectDuplicatesAndInvalidChildren(): void
    {
        $scratch = ScratchDatabase::create(); if ($scratch === null) return;
        try {
            (new MigrationRunner($scratch->connection(), dirname(__DIR__) . '/api/database/migrations'))->run();
            $pdo = $scratch->pdo;
            $pdo->exec("INSERT INTO businesses (name, slug) VALUES ('Demo','demo')");
            $pdo->exec("INSERT INTO users (business_id,name,email,password_hash) VALUES (1,'A','a@example.test','hash')");
            $this->assertThrows(Throwable::class, static fn () => $pdo->exec("INSERT INTO users (business_id,name,email,password_hash) VALUES (1,'B','a@example.test','hash')"));
            $this->assertThrows(Throwable::class, static fn () => $pdo->exec("INSERT INTO branches (business_id,name) VALUES (999,'X')"));
        } finally { $scratch->drop(); }
    }
}

final class ScratchDatabase
{
    public string $name;
    private function __construct(public PDO $pdo, private PDO $server) {}
    public static function create(string $prefix = 'vo_installer_test_'): ?self
    {
        try { $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
        catch (Throwable $e) {
            if (getenv('VO_SKIP_DB') === '1') { echo 'SKIP MariaDB unavailable: ' . $e->getMessage() . PHP_EOL; return null; }
            throw new RuntimeException('MariaDB is REQUIRED for DB tests (start XAMPP MySQL). Set VO_SKIP_DB=1 to skip explicitly.', 0, $e);
        }
        $name = $prefix . bin2hex(random_bytes(5));
        $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$name;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $self = new self($pdo, $server); $self->name = $name; return $self;
    }
    public function connection(): PdoConnection { return new PdoConnection("mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4", 'root', ''); }
    public function tables(): array { return array_column($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0); }
    public function engine(string $table): string { return (string) $this->pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $this->pdo->quote($table))->fetchColumn(); }
    public function charset(string $table): string { return (string) $this->pdo->query("SELECT CCSA.CHARACTER_SET_NAME FROM information_schema.TABLES T JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY CCSA ON CCSA.COLLATION_NAME=T.TABLE_COLLATION WHERE T.TABLE_SCHEMA=DATABASE() AND T.TABLE_NAME=" . $this->pdo->quote($table))->fetchColumn(); }
    public function drop(): void { $this->server->exec("DROP DATABASE IF EXISTS `$this->name`"); }
}
