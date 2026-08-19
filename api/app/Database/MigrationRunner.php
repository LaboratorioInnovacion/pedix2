<?php declare(strict_types=1);
namespace VO\Database;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(private Connection $connection, private string $path) {}

    public function run(): array
    {
        $this->connection->execute("CREATE TABLE IF NOT EXISTS schema_migrations (version varchar(32) primary key, name varchar(191), applied_at datetime not null)");
        $applied = array_column($this->connection->select('SELECT version FROM schema_migrations'), 'version');
        $ran = [];
        foreach ($this->migrations() as $migration) {
            if (in_array($migration['version'], $applied, true)) continue;
            $this->connection->transaction(function (Connection $db) use ($migration): void {
                $body = $this->load($migration['file']);
                is_callable($body) ? $body($db) : $db->execute((string) $body);
                $db->execute('INSERT INTO schema_migrations (version, name, applied_at) VALUES (?, ?, CURRENT_TIMESTAMP)', [$migration['version'], $migration['name']]);
            });
            $ran[] = $migration['version'] . ' ' . $migration['name'];
        }
        return $ran;
    }

    private function migrations(): array
    {
        $files = glob(rtrim($this->path, '/\\') . '/*') ?: [];
        $seen = $out = [];
        foreach ($files as $file) {
            if (!preg_match('/^(\d+)_([a-z0-9_\-]+)\.(sql|sql\.php)$/', basename($file), $m)) continue;
            if (isset($seen[$m[1]])) throw new RuntimeException('Duplicate migration version: ' . $m[1]);
            $seen[$m[1]] = true;
            $out[] = ['version' => $m[1], 'name' => $m[2], 'file' => $file];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['version'], $b['version']));
        return $out;
    }

    private function load(string $file): mixed
    {
        if (str_ends_with($file, '.php')) return require $file;
        return file_get_contents($file) ?: '';
    }
}
