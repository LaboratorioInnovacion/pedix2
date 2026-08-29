<?php declare(strict_types=1);
namespace VO\Audit;

use PDO;

final class AuditService
{
    private const SECRET_KEYS = ['password', 'passwd', 'token', 'secret', 'api_key', 'apikey'];

    public function __construct(private PDO $pdo) {}

    public function append(?array $actor, string $action, ?string $entity, array $metadata, ?string $requestId): void
    {
        [$entityType, $entityId] = $this->parseEntity($entity);
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (actor_type, actor_id, action, entity_type, entity_id, metadata_json, request_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $actor['type'] ?? 'system',
            isset($actor['id']) ? (int)$actor['id'] : null,
            $action,
            $entityType,
            $entityId,
            json_encode($this->safeMetadata($metadata), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $requestId,
        ]);
    }

    private function parseEntity(?string $entity): array
    {
        if ($entity === null || $entity === '') return [null, null];
        if (str_contains($entity, ':')) {
            [$type, $id] = explode(':', $entity, 2);
            return [$type !== '' ? $type : null, ctype_digit($id) ? (int)$id : null];
        }
        return [$entity, null];
    }

    private function safeMetadata(array $metadata): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string)$key), self::SECRET_KEYS, true)) continue;
            $safe[$key] = is_array($value) ? $this->safeMetadata($value) : $value;
        }
        return $safe;
    }
}
