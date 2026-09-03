<?php declare(strict_types=1);
namespace VO\Notifications;

use Throwable;

/**
 * WhatsApp bridge transport (spec N6, master sec 18): opaque JSON POST
 * {to, message} carrying the configured bearer token, ≥5s timeout by default,
 * injectable $executor replaces the HTTP layer in tests (contract:
 * fn(string $url, array $headers, string $payload): array{status:int,body:string}).
 * Any non-2xx, timeout, or transport error becomes a typed failure; neither the
 * bridge URL nor the token ever appears in error strings. The email subject is
 * ignored on this channel.
 */
final class WhatsAppClient implements NotificationTransport
{
    public function __construct(
        private string $bridgeUrl,
        private string $token,
        private int $timeoutSec = 5,
        private ?\Closure $executor = null,
    ) {}

    public function send(string $to, string $subject, string $body): array
    {
        try {
            $payload = json_encode(['to' => $to, 'message' => $body], JSON_UNESCAPED_UNICODE);
            if ($payload === false) return ['ok' => false, 'error' => 'whatsapp payload encode failed'];
            $headers = ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json'];
            $result = $this->executor !== null
                ? ($this->executor)($this->bridgeUrl, $headers, $payload)
                : $this->post($payload, $headers);
            $status = is_array($result) ? (int) ($result['status'] ?? 0) : 0;
            if ($status >= 200 && $status < 300) return ['ok' => true, 'error' => null];
            if ($status === 0) return ['ok' => false, 'error' => 'whatsapp bridge unreachable or timed out'];
            return ['ok' => false, 'error' => 'whatsapp bridge error HTTP ' . $status];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'whatsapp send failed: ' . $e->getMessage()];
        }
    }

    /** @return array{status:int,body:string} */
    private function post(string $payload, array $headers): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $payload,
            'timeout' => $this->timeoutSec,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($this->bridgeUrl, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) $status = (int) $m[1];
        }
        if ($response === false && $status === 0) return ['status' => 0, 'body' => ''];
        return ['status' => $status, 'body' => $response === false ? '' : $response];
    }
}
