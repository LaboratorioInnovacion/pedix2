<?php declare(strict_types=1);
namespace VO\Installer;
final class InstallerSession
{
    public function start(): void { if (session_status() === PHP_SESSION_ACTIVE) return; session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>(($_SERVER['HTTPS'] ?? '') === 'on')]); session_name('vo_install'); session_start(); if (!isset($_SESSION['_started'])) { session_regenerate_id(true); $_SESSION['_started']=true; } }
    public function csrf(): string { $this->start(); return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
    public function valid(?string $token): bool { $this->start(); return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token); }
    public function get(string $key, mixed $default=null): mixed { $this->start(); return $_SESSION[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->start(); $_SESSION[$key] = $value; }
    public function clearSecrets(): void { $this->start(); unset($_SESSION['db']['password'], $_SESSION['data']['admin_password'], $_SESSION['data']['admin_password_confirm']); }
    public function destroy(): void { $this->start(); $_SESSION = []; session_destroy(); }
}
