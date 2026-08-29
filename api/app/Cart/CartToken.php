<?php declare(strict_types=1);
namespace VO\Cart;

final class CartToken
{
    public const COOKIE = 'vo_cart';
    public static function issue(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    public static function valid(mixed $token): bool { return is_string($token) && (bool)preg_match('/^[A-Za-z0-9_-]{43}$/', $token); }
    public static function hash(string $token): string { return hash('sha256', $token); }
    public static function expiresAt(): string { return date('Y-m-d H:i:s', time() + 7200); }
    public static function cookie(string $token): string { return self::COOKIE . '=' . rawurlencode($token) . '; Path=/; Max-Age=7200; HttpOnly; Secure; SameSite=Strict'; }
}
