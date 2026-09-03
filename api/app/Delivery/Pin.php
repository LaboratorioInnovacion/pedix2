<?php declare(strict_types=1);
namespace VO\Delivery;

/**
 * Deterministic delivery PIN per design: 6 digits derived from
 * HMAC-SHA256(per-business pin key, "{deliveryId}:{orderId}") — first 3 bytes
 * mod 10^6, zero-padded. Stored only as SHA-256; recomputable for public
 * redisplay without keeping plaintext; compared in constant time.
 */
final class Pin
{
    public static function code(string $businessKey, int $deliveryId, int $orderId): string
    {
        $mac = hash_hmac('sha256', $deliveryId . ':' . $orderId, $businessKey, true);
        $num = ((ord($mac[0]) << 16) | (ord($mac[1]) << 8) | ord($mac[2])) % 1000000;
        return str_pad((string)$num, 6, '0', STR_PAD_LEFT);
    }

    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    public static function verify(string $code, ?string $hash): bool
    {
        return $hash !== null && $hash !== '' && hash_equals($hash, self::hash($code));
    }
}
