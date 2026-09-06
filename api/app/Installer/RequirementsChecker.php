<?php declare(strict_types=1);
namespace VO\Installer;
final class RequirementsChecker
{
    public function __construct(private string $configDir, private string $storageDir, private InstalledConfig $lock) {}
    public function check(): array
    {
        $items = [
            ['PHP 8.1 o superior', version_compare(PHP_VERSION, '8.1.0', '>=')],
            ['Extensión pdo_mysql disponible', extension_loaded('pdo_mysql')],
            ['Carpeta api/config escribible', $this->writable($this->configDir)],
            ['Carpeta api/storage escribible', $this->writable($this->storageDir)],
            ['Instalación previa ausente', !$this->lock->isInstalled()],
        ];
        if (getenv('VO_INSTALLER_FORCE_REQUIREMENT_FAIL')) $items[] = ['Requisito de prueba', false];
        return $items;
    }
    public function passes(): bool { foreach ($this->check() as $i) if (!$i[1]) return false; return true; }
    private function writable(string $dir): bool { return (is_dir($dir) || @mkdir($dir, 0775, true)) && is_writable($dir); }
}
