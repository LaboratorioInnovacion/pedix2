<?php declare(strict_types=1);
namespace VO\Payments;

use VO\Database\Connection;

final class PaymentRepository
{
    public function __construct(private Connection $db) {}

    public function insert(array $p): array
    {
        $cols = ['order_id','business_id','method','state','amount_cents','currency','external_reference','provider_payment_id','provider_preference_id','provider_status','provider_status_detail','preference_init_point','proof_path','verified_by','verified_at','expires_at'];
        $this->db->execute('INSERT INTO payments (' . implode(',', $cols) . ') VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')', array_map(fn($c) => $p[$c] ?? null, $cols));
        return $this->getById((int)($this->one('SELECT LAST_INSERT_ID() id')['id'] ?? 0)) ?? [];
    }

    public function getByOrderId(int $orderId): ?array { return $this->one('SELECT * FROM payments WHERE order_id=? LIMIT 1', [$orderId]); }
    public function getById(int $id): ?array { return $this->one('SELECT * FROM payments WHERE id=? LIMIT 1', [$id]); }
    public function getByExternalReference(string $externalReference): ?array { return $this->one('SELECT * FROM payments WHERE external_reference=? LIMIT 1', [$externalReference]); }

    public function updateState(int $id, string $state, array $extra = []): array
    {
        $allowed = ['verified_by','verified_at','expires_at','provider_payment_id','provider_status','provider_status_detail'];
        $sets = ['state=?']; $params = [$state];
        foreach ($allowed as $column) if (array_key_exists($column, $extra)) { $sets[] = $column . '=?'; $params[] = $extra[$column]; }
        $params[] = $id;
        $this->db->execute('UPDATE payments SET ' . implode(',', $sets) . ' WHERE id=?', $params);
        return $this->getById($id) ?? [];
    }

    public function updatePreference(int $id, string $externalReference, ?string $preferenceId, ?string $initPoint): array
    {
        $this->db->execute('UPDATE payments SET external_reference=?,provider_preference_id=?,preference_init_point=? WHERE id=?', [$externalReference,$preferenceId,$initPoint,$id]);
        return $this->getById($id) ?? [];
    }

    public function attachProof(int $id, string $path): array
    {
        $this->db->execute('UPDATE payments SET proof_path=? WHERE id=?', [$path,$id]);
        return $this->getById($id) ?? [];
    }

    public function insertEvent(int $paymentId, string $eventType, array $payload = []): void
    {
        $json = $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $businessId = (int)($this->getById($paymentId)['business_id'] ?? 1);
        $this->db->execute('INSERT INTO payment_events (payment_id,business_id,event_type,payload_json) VALUES (?,?,?,?)', [$paymentId,$businessId,$eventType,$json]);
    }

    public function insertMpEvent(?int $paymentId, int $businessId, string $eventType, ?string $providerEventId, ?string $externalReference, array $payload, string $resultState): bool
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $this->db->execute(
            'INSERT IGNORE INTO payment_events (payment_id,business_id,provider,event_type,provider_event_id,external_reference,payload_json,signature_valid,result_state,processed_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())',
            [$paymentId, $businessId, 'mercadopago', $eventType, $providerEventId, $externalReference, $json, 1, $resultState]
        ) === 1;
    }

    public function setting(int $businessId, string $key): ?string
    {
        $row = $this->one('SELECT setting_value FROM business_settings WHERE business_id=? AND setting_key=? LIMIT 1', [$businessId,$key]);
        return $row ? (string)$row['setting_value'] : null;
    }

    public function orderItems(int $orderId): array { return $this->db->select('SELECT * FROM order_items WHERE order_id=? ORDER BY id', [$orderId]); }

    public function firstBusinessId(): int
    {
        return (int)($this->one('SELECT id FROM businesses ORDER BY id LIMIT 1')['id'] ?? 1);
    }

    public function findStalePending(int $hours): array
    {
        return $this->db->select("SELECT * FROM payments WHERE state IN ('pending','pending_verification') AND (expires_at <= CURRENT_TIMESTAMP OR (expires_at IS NULL AND created_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? HOUR)))", [$hours]);
    }

    private function one(string $sql, array $p=[]): ?array { $r = $this->db->select($sql, $p); return $r[0] ?? null; }
}
