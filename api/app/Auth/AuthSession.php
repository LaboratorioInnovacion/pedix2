<?php declare(strict_types=1);
namespace VO\Auth;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class AuthSession
{
    public const IDLE_SECONDS = 1800;
    public const ABSOLUTE_SECONDS = 43200;

    public function __construct(private PDO $pdo) {}

    public static function configureCookie(bool $https): void
    {
        if (headers_sent()) return;
        session_name('vo_admin');
        session_set_cookie_params(self::cookieParams($https));
    }

    public static function cookieParams(bool $https): array { return ['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]; }

    public static function hash(string $value): string { return hash('sha256', $value); }

    public function createForLogin(int $userId, ?string $oldSid = null, ?string $sid = null, ?string $ip = null): array
    {
        $oldSid ??= session_id() ?: null;
        $sid ??= bin2hex(random_bytes(32));
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
            $sid = session_id();
        }
        $_SESSION['auth_sid'] = $sid;
        $now = gmdate('Y-m-d H:i:s');
        $absolute = gmdate('Y-m-d H:i:s', time() + self::ABSOLUTE_SECONDS);
        $stmt = $this->pdo->prepare('INSERT INTO auth_sessions (sid_hash,user_id,ip_hash,created_at,last_seen_at,absolute_expires_at) VALUES (?,?,?,?,?,?)');
        $stmt->execute([self::hash($sid), $userId, $ip !== null ? self::hash($ip) : null, $now, $now, $absolute]);
        return ['old_sid' => $oldSid, 'sid' => $sid];
    }

    public function validate(string $sid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM auth_sessions WHERE sid_hash = ? LIMIT 1');
        $stmt->execute([self::hash($sid)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !empty($row['revoked_at'])) return null;
        $now = time();
        if ($this->utcTimestamp((string)$row['absolute_expires_at']) <= $now || $this->utcTimestamp((string)$row['last_seen_at']) <= $now - self::IDLE_SECONDS) {
            $this->logout($sid); return null;
        }
        $this->pdo->prepare('UPDATE auth_sessions SET last_seen_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $row['id']]);
        $row['last_seen_at'] = gmdate('Y-m-d H:i:s');
        return $row;
    }

    public function logout(?string $sid): void
    {
        if ($sid === null || $sid === '') return;
        $this->pdo->prepare('UPDATE auth_sessions SET revoked_at = COALESCE(revoked_at, ?) WHERE sid_hash = ?')->execute([gmdate('Y-m-d H:i:s'), self::hash($sid)]);
    }

    private function utcTimestamp(string $date): int
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date, new DateTimeZone('UTC'));
        return $parsed ? $parsed->getTimestamp() : 0;
    }
}
