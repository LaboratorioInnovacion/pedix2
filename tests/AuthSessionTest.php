<?php declare(strict_types=1);
namespace Tests;

use PDO;
use VO\Auth\AuthSession;
use VO\Database\MigrationRunner;
use VO\Database\PdoConnection;

final class AuthSessionTest extends TestCase
{
    public function testMigration002CreatesAuthTablesAndIndexes(): void
    {
        $scratch = AuthScratchDatabase::create(); if ($scratch === null) return;
        try {
            (new MigrationRunner($scratch->connection(), dirname(__DIR__) . '/api/database/migrations'))->run();
            $tables = $scratch->tables();
            $this->assertTrue(in_array('auth_sessions', $tables, true));
            $this->assertTrue(in_array('login_attempts', $tables, true));
            $this->assertTrue($scratch->hasIndex('auth_sessions', 'uq_auth_sessions_sid_hash'));
            $this->assertTrue($scratch->hasIndex('login_attempts', 'idx_login_attempts_lookup'));
        } finally { $scratch->drop(); }
    }

    public function testHardenedCookieFlagsAndRegeneratedLoginSession(): void
    {
        $params = AuthSession::cookieParams(true);
        $this->assertSame(true, $params['httponly']);
        $this->assertSame(true, $params['secure']);
        $this->assertSame('Lax', $params['samesite']);

        $scratch = AuthScratchDatabase::create(); if ($scratch === null) return;
        try {
            $scratch->migrateAndSeedUser();
            $auth = new AuthSession($scratch->pdo);
            $created = $auth->createForLogin(1, 'old-session-id', 'new-session-id', '127.0.0.1');
            $this->assertSame('old-session-id', $created['old_sid']);
            $this->assertSame('new-session-id', $created['sid']);
            $this->assertSame(1, (int)$scratch->pdo->query('SELECT COUNT(*) FROM auth_sessions WHERE sid_hash=SHA2("new-session-id",256) AND revoked_at IS NULL')->fetchColumn());
        } finally { $scratch->drop(); }
    }

    public function testValidateRefreshesLastSeenAndRejectsExpiredOrRevoked(): void
    {
        $scratch = AuthScratchDatabase::create(); if ($scratch === null) return;
        try {
            $scratch->migrateAndSeedUser(); $auth = new AuthSession($scratch->pdo);
            $auth->createForLogin(1, 'old', 'valid-sid', '127.0.0.1');
            $recent = gmdate('Y-m-d H:i:s', time() - 60);
            $scratch->pdo->prepare('UPDATE auth_sessions SET last_seen_at=?')->execute([$recent]);
            $before = (string)$scratch->pdo->query('SELECT last_seen_at FROM auth_sessions')->fetchColumn();
            $this->assertTrue($auth->validate('valid-sid') !== null, 'Valid session should authenticate.');
            $after = (string)$scratch->pdo->query('SELECT last_seen_at FROM auth_sessions')->fetchColumn();
            $this->assertTrue($after >= $before, 'last_seen_at should refresh.');
            $scratch->pdo->exec("UPDATE auth_sessions SET absolute_expires_at='2000-01-01 00:00:00'");
            $this->assertSame(null, $auth->validate('valid-sid'));
            $auth->createForLogin(1, 'old', 'revoked-sid', '127.0.0.1'); $auth->logout('revoked-sid');
            $this->assertSame(null, $auth->validate('revoked-sid'));
        } finally { $scratch->drop(); }
    }

    public function testLogoutIsIdempotent(): void
    {
        $scratch = AuthScratchDatabase::create(); if ($scratch === null) return;
        try {
            $scratch->migrateAndSeedUser(); $auth = new AuthSession($scratch->pdo);
            $auth->createForLogin(1, 'old', 'logout-sid', '127.0.0.1');
            $auth->logout('logout-sid'); $auth->logout('logout-sid'); $auth->logout('missing-sid');
            $this->assertSame(1, (int)$scratch->pdo->query('SELECT COUNT(*) FROM auth_sessions WHERE sid_hash=SHA2("logout-sid",256) AND revoked_at IS NOT NULL')->fetchColumn());
        } finally { $scratch->drop(); }
    }
}

final class AuthScratchDatabase
{
    private function __construct(public string $name, public PDO $pdo, private PDO $server) {}
    public static function create(): ?self { try { $s=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); } catch (\Throwable $e) { if (getenv('VO_SKIP_DB') === '1') { echo 'SKIP MariaDB unavailable: '.$e->getMessage().PHP_EOL; return null; } throw new RuntimeException('MariaDB is REQUIRED for DB tests (start XAMPP MySQL). Set VO_SKIP_DB=1 to skip explicitly.', 0, $e); } $n='vo_auth_test_'.bin2hex(random_bytes(5)); $s->exec("CREATE DATABASE `$n` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $p=new PDO("mysql:host=127.0.0.1;port=3306;dbname=$n;charset=utf8mb4",'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); return new self($n,$p,$s); }
    public function connection(): PdoConnection { return new PdoConnection("mysql:host=127.0.0.1;port=3306;dbname=$this->name;charset=utf8mb4", 'root', ''); }
    public function migrateAndSeedUser(): void { (new MigrationRunner($this->connection(), dirname(__DIR__) . '/api/database/migrations'))->run(); $this->pdo->exec("INSERT INTO businesses (id,name) VALUES (1,'Demo')"); $this->pdo->exec("INSERT INTO users (id,business_id,name,email,password_hash,is_active) VALUES (1,1,'Admin','admin@example.test','".password_hash('Secret123', PASSWORD_BCRYPT, ['cost'=>4])."',1)"); }
    public function tables(): array { return array_column($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0); }
    public function hasIndex(string $table, string $index): bool { $q=$this->pdo->prepare('SHOW INDEX FROM '.$table.' WHERE Key_name = ?'); $q->execute([$index]); return (bool)$q->fetch(); }
    public function drop(): void { $this->server->exec("DROP DATABASE IF EXISTS `$this->name`"); }
}
