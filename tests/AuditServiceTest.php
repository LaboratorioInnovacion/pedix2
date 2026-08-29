<?php declare(strict_types=1);
namespace Tests;

use VO\Audit\AuditService;

require_once __DIR__ . '/AuthSessionTest.php';

final class AuditServiceTest extends TestCase
{
    public function testAppendWritesAuditEventWithRequestId(): void
    {
        $scratch = AuthScratchDatabase::create(); if ($scratch === null) return;
        try {
            $scratch->migrateAndSeedUser();
            $audit = new AuditService($scratch->pdo);
            $audit->append(['type' => 'user', 'id' => 1], 'authz.denied', 'order:99', ['reason' => 'missing_permission'], 'req-audit');

            $row = $scratch->pdo->query('SELECT actor_type,actor_id,action,entity_type,entity_id,metadata_json,request_id FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
            $this->assertSame('user', $row['actor_type']);
            $this->assertSame(1, (int)$row['actor_id']);
            $this->assertSame('authz.denied', $row['action']);
            $this->assertSame('order', $row['entity_type']);
            $this->assertSame(99, (int)$row['entity_id']);
            $this->assertSame('req-audit', $row['request_id']);
            $this->assertSame('missing_permission', json_decode($row['metadata_json'], true)['reason']);
        } finally { $scratch->drop(); }
    }

    public function testAppendStripsPlaintextSecretMetadata(): void
    {
        $scratch = AuthScratchDatabase::create(); if ($scratch === null) return;
        try {
            $scratch->migrateAndSeedUser();
            $audit = new AuditService($scratch->pdo);
            $audit->append(['type' => 'user', 'id' => 1], 'auth.login_failed', 'user:1', [
                'password' => 'Secret123',
                'nested' => ['token' => 'plain-token', 'safe' => 'kept'],
            ], 'req-secret');

            $json = (string)$scratch->pdo->query('SELECT metadata_json FROM audit_log ORDER BY id DESC LIMIT 1')->fetchColumn();
            $metadata = json_decode($json, true);
            $this->assertTrue(!str_contains($json, 'Secret123'));
            $this->assertTrue(!str_contains($json, 'plain-token'));
            $this->assertTrue(!array_key_exists('password', $metadata));
            $this->assertTrue(!array_key_exists('token', $metadata['nested']));
            $this->assertSame('kept', $metadata['nested']['safe']);
        } finally { $scratch->drop(); }
    }
}
