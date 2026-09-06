<?php declare(strict_types=1);
namespace Tests;
use RuntimeException; use VO\Config\Config; use VO\Installer\InstalledConfig;

final class ConfigOverlayTest extends TestCase
{
    public function testInstalledOverlayTakesPrecedenceOutsidePublicRoot(): void
    {
        $dir = $this->tempDir('config-overlay');
        file_put_contents($dir . '/app.php', "<?php return ['name'=>'Static','env'=>'production','required'=>['app.key']];");
        file_put_contents($dir . '/database.php', "<?php return ['dsn'=>'static','user'=>'static','password'=>'static'];");
        (new InstalledConfig($dir . '/private/installed.php'))->write(['dsn' => 'mysql:dbname=scratch', 'user' => 'root', 'password' => 'secret'], true);

        $config = Config::load(['config_root' => $dir, 'installed_config' => $dir . '/private/installed.php'], ['app', 'database']);

        $this->assertSame(true, $config->get('installed'));
        $this->assertSame('Static', $config->get('app.name'));
        $this->assertNotSame(null, $config->get('app.key'));
        $this->assertSame('mysql:dbname=scratch', $config->get('database.dsn'));
        $this->assertTrue(!str_contains($config->get('paths.installed_config'), 'public_html'));
    }

    public function testMissingOverlayMeansNotInstalled(): void
    {
        $dir = $this->tempDir('config-missing-overlay');
        file_put_contents($dir . '/app.php', "<?php return ['name'=>'Static','env'=>'production','required'=>['app.name']];");
        $config = Config::load(['config_root' => $dir, 'installed_config' => $dir . '/installed.php'], ['app']);
        $this->assertSame(false, (bool) $config->get('installed', false));
    }

    public function testTestsCannotWriteProductionLockPath(): void
    {
        $this->assertThrows(RuntimeException::class, static fn () => (new InstalledConfig(dirname(__DIR__) . '/api/config/installed.php'))->write(['dsn' => 'x']));
    }
}
