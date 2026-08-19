<?php declare(strict_types=1);
namespace Tests;
use VO\Database\MigrationRunner; use VO\Installer\InstallerSeeder;

final class InstallerSeederTest extends TestCase
{
    public function testSeedsOwnerPermissionsAdminHashAssignmentsAndAudit(): void
    {
        $scratch = ScratchDatabase::create(); if ($scratch === null) return;
        try {
            (new MigrationRunner($scratch->connection(), dirname(__DIR__) . '/api/database/migrations'))->run();
            $result = (new InstallerSeeder($scratch->connection()))->seed($this->input());
            $pdo = $scratch->pdo;
            $this->assertSame(16, (int) $pdo->query("SELECT COUNT(*) FROM permissions")->fetchColumn());
            $this->assertSame(16, (int) $pdo->query("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id=rp.role_id WHERE r.name='owner'")->fetchColumn());
            $user = $pdo->query("SELECT password_hash FROM users WHERE email='owner@example.test'")->fetch();
            $this->assertTrue(password_verify('change-me-now', $user['password_hash']));
            $this->assertTrue(!str_contains($user['password_hash'], 'change-me-now'), 'Plain password must not be stored.');
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM user_roles')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM user_branches')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE actor_type='installer' AND action='installer.completed'")->fetchColumn());
            $this->assertSame($result['businessId'], (int) $pdo->query('SELECT id FROM businesses')->fetchColumn());
        } finally { $scratch->drop(); }
    }

    public function testSeederIsRetrySafeAndUsesScratchDatabaseOnly(): void
    {
        $scratch = ScratchDatabase::create(); if ($scratch === null) return;
        try {
            (new MigrationRunner($scratch->connection(), dirname(__DIR__) . '/api/database/migrations'))->run();
            $seeder = new InstallerSeeder($scratch->connection());
            $first = $seeder->seed($this->input()); $second = $seeder->seed($this->input());
            $this->assertSame($first, $second);
            $this->assertSame(1, (int) $scratch->pdo->query('SELECT COUNT(*) FROM businesses')->fetchColumn());
            $this->assertSame(1, (int) $scratch->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
            $this->assertSame($scratch->name, (string) $scratch->pdo->query('SELECT DATABASE()')->fetchColumn());
            $this->assertTrue($scratch->name !== 'vo_test', 'Installer tests must not use vo_test.');
        } finally { $scratch->drop(); }
    }

    private function input(): array
    {
        return ['business_name' => 'Demo Store', 'business_slug' => 'demo-store', 'branch_name' => 'Main', 'branch_address' => '1 Demo St', 'branch_phone' => '555', 'timezone' => 'America/Argentina/Buenos_Aires', 'admin_name' => 'Owner', 'admin_email' => 'owner@example.test', 'admin_password' => 'change-me-now'];
    }
}
