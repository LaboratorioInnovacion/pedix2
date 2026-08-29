<?php declare(strict_types=1);
namespace VO\Settings;

use PDO;

final class SettingsRepository
{
    private array $cache = [];
    public function __construct(private PDO $pdo) {}
    public function get(int $businessId, string $key): ?string
    {
        if (isset($this->cache[$businessId]) && array_key_exists($key, $this->cache[$businessId])) return $this->cache[$businessId][$key];
        $s=$this->pdo->prepare('SELECT setting_key,setting_value FROM business_settings WHERE business_id=?'); $s->execute([$businessId]);
        return ($this->cache[$businessId]=array_column($s->fetchAll(PDO::FETCH_ASSOC),'setting_value','setting_key'))[$key] ?? null;
    }
    public function set(int $businessId, string $key, string $value): void
    {
        $s=$this->pdo->prepare('INSERT INTO business_settings (business_id,setting_key,setting_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'); $s->execute([$businessId,$key,$value]); unset($this->cache[$businessId]);
    }
}
