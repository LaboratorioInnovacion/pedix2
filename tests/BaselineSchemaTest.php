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
            $this->assertSame(['001 create_baseline'], $runner->run());
            $this->assertSame([], $runner->run());
            $tables = $scratch->tables(); sort($tables);
            $expected = ['audit_log','branches','branch_settings','businesses','business_settings','permissions','roles','role_permissions','schema_migrations','users','user_branches','user_roles']; sort($expected);
            $this->assertSame($expected, $tables);
            $version = $scratch->pdo->query('SELECT version FROM schema_migrations')->fetchColumn();
            $this->assertSame('001', $version);
            foreach (['orders','payments','deliveries','catalog','customers','sessions','auth_sessions','modules','backups','workers'] as $deferred) $this->assertTrue(!in_array($deferred, $tables, true), $deferred . ' must be absent.');
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
    public static function create(): ?self
    {
        try { $server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
        catch (Throwable $e) { echo 'SKIP MariaDB unavailable: ' . $e->getMessage() . PHP_EOL; return null; }
        $name = 'vo_installer_test_' . bin2hex(random_bytes(5));
        $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$name;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $self = new self($pdo, $server); $self->name = $name; return $self;
    }
    public function connection(): PdoConnection { return new PdoConnection("mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4", 'root', ''); }
    public function tables(): array { return array_column($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0); }
    public function drop(): void { $this->server->exec("DROP DATABASE IF EXISTS `$this->name`"); }
}
