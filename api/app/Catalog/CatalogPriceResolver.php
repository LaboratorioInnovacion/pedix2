<?php declare(strict_types=1);
namespace VO\Catalog;

final class CatalogPriceResolver
{
    public static function resolve(array $item, ?array $branchItem = null, ?array $variant = null, ?array $branchVariant = null): ?int
    {
        foreach ([$branchVariant['price_override_cents'] ?? null, $variant['price_cents'] ?? null, $branchItem['price_override_cents'] ?? null, $item['base_price_cents'] ?? null] as $v) {
            if ($v !== null && $v !== '') return (int) $v;
        }
        return null;
    }
}
