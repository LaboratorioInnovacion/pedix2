<?php declare(strict_types=1);
namespace Tests;
use RuntimeException; use VO\Database\Connection; use VO\Database\MigrationRunner;

final class MigrationRunnerTest extends TestCase
{
    public function testCreatesSchemaAndAppliesPendingOnlyOnce(): void
    {
        $dir = $this->tempDir('migrations'); $db = new FakeConnection();
        file_put_contents($dir . '/001_create_demo.sql.php', "<?php return 'CREATE TABLE demo (id int)';");
        $this->assertSame(['001 create_demo'], (new MigrationRunner($db, $dir))->run());
        $this->assertSame([], (new MigrationRunner($db, $dir))->run());
        $this->assertSame(1, $db->appliedCount); $this->assertTrue(str_contains($db->executed[0][0], 'CREATE TABLE IF NOT EXISTS schema_migrations'));
    }

    public function testRefusesDuplicateVersions(): void
    {
        $dir = $this->tempDir('duplicate-migrations'); file_put_contents($dir . '/001_a.sql', 'SELECT 1'); file_put_contents($dir . '/001_b.sql', 'SELECT 2');
        $this->assertThrows(RuntimeException::class, static fn () => (new MigrationRunner(new FakeConnection(), $dir))->run());
    }

    private function tempDir(string $name): string
    {
        $dir = sys_get_temp_dir() . '/vo_' . $name . '_' . uniqid(); mkdir($dir); return $dir;
    }
}

final class FakeConnection implements Connection
{
    public array $versions = [], $executed = []; public int $appliedCount = 0;
    public function select(string $sql, array $params = []): array { return array_map(static fn ($v): array => ['version' => $v], $this->versions); }
    public function execute(string $sql, array $params = []): int
    {
        $this->executed[] = [$sql, $params];
        if (str_starts_with($sql, 'INSERT INTO schema_migrations')) { $this->versions[] = $params[0]; $this->appliedCount++; }
        return 1;
    }
    public function transaction(callable $fn): mixed { return $fn($this); }
}
