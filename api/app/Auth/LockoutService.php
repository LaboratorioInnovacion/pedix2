<?php declare(strict_types=1);
namespace VO\Auth;

use PDO;

final class LockoutService
{
    public function __construct(private PDO $pdo, private int $threshold = 5, private int $windowSeconds = 900) {}
    public function isLocked(string $identifierHash, string $ipHash): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier_hash=? AND ip_hash=? AND success=0 AND attempted_at >= ?');
        $stmt->execute([$identifierHash, $ipHash, gmdate('Y-m-d H:i:s', time() - $this->windowSeconds)]);
        return (int)$stmt->fetchColumn() >= $this->threshold;
    }
    public function record(string $identifierHash, string $ipHash, bool $success): void
    {
        if ($success) $this->clear($identifierHash, $ipHash);
        $this->pdo->prepare('INSERT INTO login_attempts (identifier_hash,ip_hash,attempted_at,success) VALUES (?,?,?,?)')->execute([$identifierHash, $ipHash, gmdate('Y-m-d H:i:s'), $success ? 1 : 0]);
    }
    public function clear(string $identifierHash, string $ipHash): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE identifier_hash=? AND ip_hash=? AND success=0')->execute([$identifierHash, $ipHash]);
    }
}
