<?php declare(strict_types=1);
namespace VO\Payments;

use RuntimeException;

final class MpApiException extends RuntimeException
{
    public function __construct(public readonly int $statusCode, string $message)
    {
        parent::__construct($message, $statusCode);
    }
}

final class MpClient
{
    public function __construct(private string $baseUrl = 'https://api.mercadopago.com', private string $accessToken = '') {}

    public function createPreference(array $payload): array
    {
        return $this->request('POST', '/checkout/preferences', $payload);
    }

    public function getPayment(string $paymentId): array
    {
        return $this->request('GET', '/v1/payments/' . rawurlencode($paymentId));
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $ch = curl_init(rtrim($this->baseUrl, '/') . $path);
        if ($ch === false) {
            throw new MpApiException(0, 'Mercado Pago request could not be initialized.');
        }

        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->accessToken];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($payload !== null) {
            curl_setopt_array($ch, [
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => [...$headers, 'Content-Type: application/json'],
            ]);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new MpApiException(0, 'Mercado Pago request failed.');
        }
        if ($status < 200 || $status >= 300) {
            throw new MpApiException($status, 'Mercado Pago API returned HTTP ' . $status . '.');
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
