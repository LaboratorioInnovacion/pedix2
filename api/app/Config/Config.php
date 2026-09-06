<?php
declare(strict_types=1);
namespace VO\Config;
use RuntimeException;
final class Config
{
    public function __construct(private array $values) {}
    public static function load(array $paths, array $files): self
    {
        $values = ['paths' => $paths];
        foreach ($files as $name) {
            $file = $paths['config_root'] . '/' . $name . '.php';
            if (!is_file($file)) throw new RuntimeException('Required configuration is unavailable.');
            $values[$name] = require $file;
        }
        $overlay = $paths['installed_config'] ?? $paths['config_root'] . '/installed.php';
        if (is_file($overlay)) {
            $installed = require $overlay;
            if (!is_array($installed)) throw new RuntimeException('Required configuration is unavailable.');
            $values = self::merge($values, $installed);
        }
        $config = new self($values);
        foreach ($config->get('app.required', []) as $key) {
            if ($config->get($key) === null || $config->get($key) === '') throw new RuntimeException('Required configuration is unavailable.');
        }
        return $config;
    }
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) return $default;
            $value = $value[$part];
        }
        return $value;
    }
    private static function merge(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key]) ? self::merge($base[$key], $value) : $value;
        }
        return $base;
    }
}
