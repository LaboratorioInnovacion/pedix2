<?php declare(strict_types=1);
namespace VO\Domain;

use DomainException;
use VO\Database\Connection;

final class IdempotencyConflictException extends DomainException {}

final class DbIdempotencyStore implements IdempotencyStore
{
    public function __construct(private Connection $db) {}

    public function attempt(string $key, callable $work): IdempotencyOutcome
    {
        $stored = $this->db->select('SELECT response_json FROM idempotency_keys WHERE idempotency_key=? LIMIT 1', [$key])[0] ?? null;
        if ($stored) return new IdempotencyOutcome('replay', json_decode((string)$stored['response_json'], true));
        return $work();
    }

    public function replay(int $businessId, string $key, string $requestHash): ?array
    {
        $row = $this->db->select('SELECT * FROM idempotency_keys WHERE business_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE', [$businessId, $key])[0] ?? null;
        if (!$row) return null;
        if (!hash_equals((string)$row['request_hash'], $requestHash)) throw new IdempotencyConflictException('Idempotency key was already used with a different request.');
        return json_decode((string)$row['response_json'], true) ?: null;
    }

    public function record(int $businessId, string $key, string $requestHash, int $statusCode, array $response, ?int $orderId): void
    {
        $this->db->execute('INSERT INTO idempotency_keys (business_id,idempotency_key,request_hash,status_code,response_json,order_id,expires_at) VALUES (?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL 1 DAY)) ON DUPLICATE KEY UPDATE id=id', [$businessId, $key, $requestHash, $statusCode, json_encode($response, JSON_UNESCAPED_SLASHES), $orderId]);
    }
}
