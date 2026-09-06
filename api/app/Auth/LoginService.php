<?php declare(strict_types=1);
namespace VO\Auth;

use PDO;

final class LoginService
{
    public const GENERIC_FAILURE = 'Invalid credentials.';
    private const DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    private LockoutService $lockout;
    private AuthSession $sessions;

    public function __construct(private PDO $pdo, private mixed $audit = null)
    {
        $this->lockout = new LockoutService($pdo);
        $this->sessions = new AuthSession($pdo);
    }

    public function attempt(string $identifier, string $password, string $ip, ?string $requestId, ?string $oldSid = null, ?string $sid = null): array
    {
        $normalized = strtolower(trim($identifier));
        $identifierHash = AuthSession::hash($normalized); $ipHash = AuthSession::hash($ip);
        if ($this->lockout->isLocked($identifierHash, $ipHash)) {
            $this->audit('auth.lockout', null, $requestId, $identifierHash, $ipHash); return $this->fail();
        }
        $stmt = $this->pdo->prepare('SELECT id,password_hash,is_active FROM users WHERE LOWER(email)=? LIMIT 1');
        $stmt->execute([$normalized]); $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $hash = $user['password_hash'] ?? self::DUMMY_HASH;
        $valid = password_verify($password, (string)$hash) && $user !== null && (int)$user['is_active'] === 1;
        if (!$valid) {
            $this->lockout->record($identifierHash, $ipHash, false);
            $this->audit('auth.login_failed', null, $requestId, $identifierHash, $ipHash); return $this->fail();
        }
        $this->lockout->record($identifierHash, $ipHash, true);
        $session = $this->sessions->createForLogin((int)$user['id'], $oldSid, $sid, $ip);
        $this->pdo->prepare('UPDATE users SET last_login_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s'), $user['id']]);
        $this->audit('auth.login_success', (int)$user['id'], $requestId, $identifierHash, $ipHash);
        return ['ok' => true, 'user_id' => (int)$user['id'], 'sid' => $session['sid']];
    }

    private function fail(): array { return ['ok' => false, 'message' => self::GENERIC_FAILURE]; }
    private function audit(string $action, ?int $actorId, ?string $requestId, string $identifierHash, string $ipHash): void
    {
        if (!is_callable($this->audit)) return;
        ($this->audit)(['action' => $action, 'actor_id' => $actorId, 'request_id' => $requestId, 'metadata' => ['identifier_hash' => $identifierHash, 'ip_hash' => $ipHash]]);
    }
}
