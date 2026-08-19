<?php declare(strict_types=1);
namespace VO\Installer;
use RuntimeException;

final class InstalledConfig
{
    public function __construct(private string $path) {}
    public function path(): string { return $this->path; }
    public function isInstalled(): bool { return is_file($this->path); }

    public function write(array $database, bool $allowOverwrite = false): array
    {
        $normalized = str_replace('\\', '/', $this->path);
        $testPath = defined('VO_TESTING') && !str_contains($normalized, '/api/config/installed.php');
        if ($this->isInstalled() && (!$allowOverwrite || !$testPath)) throw new RuntimeException('Installation is already locked.');
        if (defined('VO_TESTING') && str_contains($normalized, '/api/config/installed.php')) throw new RuntimeException('Tests must use a scratch installed config path.');
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException('Required configuration is unavailable.');
        $config = [
            'installed' => true,
            'installed_at' => gmdate('c'),
            'version' => '1.0.0',
            'app' => ['key' => bin2hex(random_bytes(32))],
            'database' => $database,
        ];
        $tmp = $this->path . '.tmp.' . bin2hex(random_bytes(4));
        $bytes = file_put_contents($tmp, '<?php return ' . var_export($config, true) . ';' . PHP_EOL, LOCK_EX);
        if ($bytes === false || !rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException('Required configuration is unavailable.');
        }
        return $config;
    }
}
