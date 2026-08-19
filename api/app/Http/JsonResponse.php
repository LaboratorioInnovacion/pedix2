<?php
declare(strict_types=1);
namespace VO\Http;
final class JsonResponse
{
    public function __construct(private array $payload, private int $status = 200, private array $headers = []) { $this->headers['Content-Type'] = 'application/json; charset=utf-8'; }
    public static function ok(array $data, ?string $requestId = null, int $status = 200): self { return new self(['ok' => true, 'data' => $data, 'request_id' => $requestId], $status); }
    public static function error(string $code, string $message, int $status, ?string $requestId = null): self { return new self(['ok' => false, 'error' => ['code' => $code, 'message' => $message], 'request_id' => $requestId], $status); }
    public function withHeader(string $name, string $value): self { $copy = clone $this; $copy->headers[$name] = $value; return $copy; }
    public function withRequestId(string $requestId): self { $payload = $this->payload; $payload['request_id'] = $requestId; return new self($payload, $this->status, $this->headers); }
    public function status(): int { return $this->status; }
    public function headers(): array { return $this->headers; }
    public function body(): string { return json_encode($this->payload, JSON_UNESCAPED_SLASHES); }
    public function send(): void { http_response_code($this->status); foreach ($this->headers as $name => $value) header($name . ': ' . $value); echo $this->body(); }
}
