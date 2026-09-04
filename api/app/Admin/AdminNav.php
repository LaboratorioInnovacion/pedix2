<?php
declare(strict_types=1);
namespace VO\Admin;

use PDO;
use VO\Auth\PermissionGuard;

/**
 * Permission-aware sidebar data for layout.php. AdminController shares its PDO once per
 * request (share); templates then call entries()/allows() with the in-scope $user. No
 * caching beyond PermissionGuard's per-request memoization, so permission changes made
 * mid-request (or between HTTP requests in tests) are always honored.
 */
final class AdminNav
{
    private const ENTRIES = [
        // href, Spanish label, guard permission (null = any authenticated operator)
        ['/admin/', 'Inicio', null],
        ['/admin/catalogo', 'Catálogo', 'products.manage'],
        ['/admin/pedidos', 'Pedidos', 'products.manage'],
        ['/admin/operacion', 'Operación', 'orders.view'],
        ['/admin/pagos', 'Pagos', 'products.manage'],
        ['/admin/delivery', 'Delivery', 'deliveries.manage'],
        ['/admin/notificaciones', 'Notificaciones', 'settings.manage'],
        ['/admin/configuracion', 'Configuración', 'settings.manage'],
        ['/admin/reportes', 'Reportes', 'reports.view'],
    ];

    private static ?PDO $pdo = null;
    private static ?PermissionGuard $guard = null;

    public static function share(PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$guard = null;
    }

    public static function allows(int $userId, string $permission): bool
    {
        $guard = self::guard($userId);
        return $guard !== null && $guard->requirePermission($permission);
    }

    /** @return list<array{href:string,label:string,active:bool}> entries the user may open, active flag from the current path. */
    public static function entries(int $userId): array
    {
        $guard = self::guard($userId);
        if ($guard === null) return [];
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH) ?: '/admin/';
        $out = [];
        foreach (self::ENTRIES as [$href, $label, $permission]) {
            if ($permission !== null && !$guard->requirePermission($permission)) continue;
            $active = $href === '/admin/'
                ? ($path === '/admin/' || $path === '/admin')
                : str_starts_with($path, $href);
            $out[] = ['href' => $href, 'label' => $label, 'active' => $active];
        }
        return $out;
    }

    private static function guard(int $userId): ?PermissionGuard
    {
        if (self::$pdo === null || $userId <= 0) return null;
        return self::$guard ??= new PermissionGuard(self::$pdo, $userId);
    }
}
