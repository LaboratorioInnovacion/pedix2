<?php declare(strict_types=1);
namespace VO\Auth;

use PDO;

final class PermissionGuard
{
    private ?array $permissions = null;

    public function __construct(private PDO $pdo, private int $userId) {}

    public function requirePermission(string $key): bool
    {
        return in_array($key, $this->permissions(), true);
    }

    public function permissions(): array
    {
        if ($this->permissions !== null) return $this->permissions;
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT p.permission_key FROM user_roles ur INNER JOIN role_permissions rp ON rp.role_id = ur.role_id INNER JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = ?'
        );
        $stmt->execute([$this->userId]);
        return $this->permissions = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
