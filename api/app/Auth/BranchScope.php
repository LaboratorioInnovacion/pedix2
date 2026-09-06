<?php declare(strict_types=1);
namespace VO\Auth;

use PDO;

final class BranchScope
{
    public function __construct(private PDO $pdo) {}

    public function requireBranch(int $userId, int $branchId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM user_branches WHERE user_id = ? AND branch_id = ? LIMIT 1');
        $stmt->execute([$userId, $branchId]);
        return (bool)$stmt->fetchColumn();
    }
}
