<?php declare(strict_types=1);
namespace VO\Auth;

final class CsrfService
{
    private array $store;
    public function __construct(?array &$store = null) { if ($store === null) { if (!isset($_SESSION) || !is_array($_SESSION)) $_SESSION = []; $this->store =& $_SESSION; } else { $this->store =& $store; } }
    public function issue(): string { return $this->store['_csrf'] ??= bin2hex(random_bytes(32)); }
    public function validate(?string $token): bool { return is_string($token) && isset($this->store['_csrf']) && hash_equals($this->store['_csrf'], $token); }
}
