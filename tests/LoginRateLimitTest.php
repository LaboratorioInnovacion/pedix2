<?php declare(strict_types=1);
namespace Tests;

use VO\Auth\LoginService;

final class LoginRateLimitTest extends TestCase
{
    public function testLoginSuccessAndGenericFailuresUseSameMessage(): void
    {
        $scratch = AuthScratchDatabase::create(); if ($scratch === null) return;
        try {
            $scratch->migrateAndSeedUser(); $events = [];
            $login = new LoginService($scratch->pdo, static function (array $event) use (&$events): void { $events[] = $event; });
            $ok = $login->attempt('admin@example.test', 'Secret123', '127.0.0.1', 'req1', 'old', 'sid1');
            $this->assertSame(true, $ok['ok']);
            $badUnknown = $login->attempt('missing@example.test', 'nope', '127.0.0.1', 'req2', 'old', 'sid2');
            $badPassword = $login->attempt('admin@example.test', 'wrong', '127.0.0.1', 'req3', 'old', 'sid3');
            $scratch->pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
            $badInactive = $login->attempt('admin@example.test', 'Secret123', '127.0.0.1', 'req4', 'old', 'sid4');
            $this->assertSame($badUnknown['message'], $badPassword['message']);
            $this->assertSame($badUnknown['message'], $badInactive['message']);
        } finally { $scratch->drop(); }
    }

    public function testDummyHashPathAndLockoutBlocksGenerically(): void
    {
        $scratch = AuthScratchDatabase::create(); if ($scratch === null) return;
        try {
            $scratch->migrateAndSeedUser(); $events = [];
            $login = new LoginService($scratch->pdo, static function (array $event) use (&$events): void { $events[] = $event; });
            for ($i=0; $i<5; $i++) $login->attempt('missing@example.test', 'wrong', '10.0.0.1', 'req'.$i);
            $blocked = $login->attempt('missing@example.test', 'wrong', '10.0.0.1', 'req-block');
            $this->assertSame(false, $blocked['ok']);
            $this->assertSame(LoginService::GENERIC_FAILURE, $blocked['message']);
            $this->assertTrue(in_array('auth.lockout', array_column($events, 'action'), true));
        } finally { $scratch->drop(); }
    }

    public function testSuccessClearsAttemptsAndAuditMetadataIsSafe(): void
    {
        $scratch = AuthScratchDatabase::create(); if ($scratch === null) return;
        try {
            $scratch->migrateAndSeedUser(); $events = [];
            $login = new LoginService($scratch->pdo, static function (array $event) use (&$events): void { $events[] = $event; });
            $login->attempt('admin@example.test', 'wrong', '127.0.0.1', 'req-fail');
            $this->assertTrue((int)$scratch->pdo->query('SELECT COUNT(*) FROM login_attempts WHERE success=0')->fetchColumn() > 0);
            $login->attempt('admin@example.test', 'Secret123', '127.0.0.1', 'req-ok', 'old', 'sid-ok');
            $this->assertSame(0, (int)$scratch->pdo->query('SELECT COUNT(*) FROM login_attempts WHERE success=0')->fetchColumn());
            $last = end($events); $metadata = $last['metadata'];
            $this->assertSame('req-ok', $last['request_id']);
            $this->assertTrue(isset($metadata['identifier_hash']) && strlen($metadata['identifier_hash']) === 64);
            $encoded = json_encode($events);
            $this->assertTrue(!str_contains($encoded, 'Secret123'));
            $this->assertTrue(!str_contains($encoded, 'admin@example.test'));
        } finally { $scratch->drop(); }
    }
}
