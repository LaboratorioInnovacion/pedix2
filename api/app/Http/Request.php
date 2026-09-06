<?php
declare(strict_types=1);
namespace VO\Http;
final class Request
{
    public function __construct(private string $method, private string $path, private array $attributes = [], private array $query = [], private array $headers = [], private array $post = [], private array $cookies = [], private string $clientIp = '') {}
    public static function fromGlobals(): self { $uri = $_SERVER['REQUEST_URI'] ?? '/'; $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : []; return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), parse_url($uri, PHP_URL_PATH) ?: '/', [], $_GET, $headers, $_POST, $_COOKIE, $_SERVER['REMOTE_ADDR'] ?? ''); }
    public static function newRequestId(): string { return bin2hex(random_bytes(16)); }
    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }
    public function withAttribute(string $key, mixed $value): self { $copy = clone $this; $copy->attributes[$key] = $value; return $copy; }
    public function attribute(string $key, mixed $default = null): mixed { return $this->attributes[$key] ?? $default; }
    public function post(string $key, mixed $default = null): mixed { return $this->post[$key] ?? $default; }
    public function header(string $key, mixed $default = null): mixed { foreach ($this->headers as $name => $value) if (strcasecmp((string)$name, $key) === 0) return $value; return $default; }
    public function cookie(string $key, mixed $default = null): mixed { return $this->cookies[$key] ?? $default; }
    public function query(string $key, mixed $default = null): mixed { return $this->query[$key] ?? $default; }
    public function clientIp(): string { return $this->clientIp; }
}
