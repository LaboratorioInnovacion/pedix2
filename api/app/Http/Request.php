<?php
declare(strict_types=1);
namespace VO\Http;
final class Request
{
    public function __construct(private string $method, private string $path, private array $attributes = []) {}
    public static function fromGlobals(): self { $uri = $_SERVER['REQUEST_URI'] ?? '/'; return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), parse_url($uri, PHP_URL_PATH) ?: '/'); }
    public static function newRequestId(): string { return bin2hex(random_bytes(16)); }
    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }
    public function withAttribute(string $key, mixed $value): self { $copy = clone $this; $copy->attributes[$key] = $value; return $copy; }
    public function attribute(string $key, mixed $default = null): mixed { return $this->attributes[$key] ?? $default; }
}
