<?php declare(strict_types=1);
namespace VO\Notifications;

use VO\Database\Connection;

/**
 * Transactional-outbox access for notification_events (specs N1, N4). enqueue()
 * is a plain INSERT so it joins the CALLER's transaction (no commit here);
 * pending()/markSent()/markFailed() serve the dispatch sweep. markFailed()
 * increments attempts and flips the row to `failed` once the cap is reached.
 */
final class NotificationRepository
{
    private const STATE_FAILED = 'failed';
    private const MAX_ATTEMPTS = 3;

    public function __construct(private Connection $db) {}

    /** @param array<string,int|string>|null $context ids-only payload (order/delivery ids, order number) */
    public function enqueue(int $businessId, string $event, string $channel, string $recipient, ?string $subject, ?array $context): int
    {
        $this->db->execute(
            'INSERT INTO notification_events (business_id,event,channel,recipient,subject,context_json) VALUES (?,?,?,?,?,?)',
            [$businessId, $event, $channel, $recipient, $subject, $context === null ? null : json_encode($context, JSON_UNESCAPED_UNICODE)]
        );
        return (int)$this->db->select('SELECT LAST_INSERT_ID() id', [])[0]['id'];
    }

    /** @return array<int,array<string,mixed>> pending rows with attempts under the cap, oldest first */
    public function pending(int $limit = 10, int $maxAttempts = 3, ?int $businessId = null): array
    {
        $sql = "SELECT * FROM notification_events WHERE state='pending' AND attempts<?";
        $params = [$maxAttempts];
        if ($businessId !== null) { $sql .= ' AND business_id=?'; $params[] = $businessId; }
        return $this->db->select($sql . ' ORDER BY id LIMIT ' . max(1, $limit), $params);
    }

    public function markSent(int $id): void
    {
        $this->db->execute("UPDATE notification_events SET state='sent', sent_at=NOW() WHERE id=?", [$id]);
    }

    /** Records one failed attempt; the cap converts the row to `failed` (never retried again). */
    public function markFailed(int $id, int $attempts, string $lastError): void
    {
        $this->db->execute(
            "UPDATE notification_events SET attempts=?, last_error=?, state=IF(? >= " . self::MAX_ATTEMPTS . ", '" . self::STATE_FAILED . "', state) WHERE id=?",
            [$attempts, mb_substr($lastError, 0, 500), $attempts, $id]
        );
    }
}
