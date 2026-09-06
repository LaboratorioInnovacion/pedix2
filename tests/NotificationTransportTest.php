<?php declare(strict_types=1);
namespace Tests;

use PDO;
use VO\Database\MigrationRunner;
use VO\Notifications\SmtpClient;
use VO\Notifications\WhatsAppClient;

/**
 * Unit A transports (specs N4–N6): full SMTP dialogue against a scripted fake
 * SMTP server (separate PHP process mirroring the FakeMpServer harness style),
 * typed failures for auth/protocol/connect/timeout paths, WhatsApp bridge
 * payload/token/non-2xx/timeout, and migration 010 schema pins. Scratch DBs use
 * the vo_notif11_test_ prefix; this file exercises sockets so it runs serially.
 */
final class NotificationTransportTest extends TestCase
{
    private const USER = 'orders-user';
    private const PASS = 'orders-pass-secret';

    public function testSmtpSuccessfulDialogueSendsFullCommandSequence(): void
    {
        $server = new FakeSmtpServer([
            ['reply' => "220 stub ESMTP\r\n"],
            ['read' => 1, 'reply' => "250-stub\r\n250-AUTH LOGIN PLAIN\r\n250-8BITMIME\r\n250 SMTPUTF8\r\n"],
            ['read' => 1, 'reply' => "334 VXNlcm5hbWU6\r\n"],  // AUTH LOGIN → "Username:"
            ['read' => 1, 'reply' => "334 UGFzc3dvcmQ6\r\n"],  // username → "Password:"
            ['read' => 1, 'reply' => "235 2.0.0 ok\r\n"],
            ['read' => 1, 'reply' => "250 2.1.0 from ok\r\n"],
            ['read' => 1, 'reply' => "250 2.1.5 to ok\r\n"],
            ['read' => 1, 'reply' => "354 go\r\n"],
            ['payload' => 1, 'reply' => "250 2.0.0 queued\r\n"],
            ['read' => 1, 'reply' => "221 2.0.0 bye\r\n", 'close' => 1],
        ]);
        try {
            $server->start();
            $client = new SmtpClient('127.0.0.1', $server->port(), self::USER, self::PASS, 'orders@demo.test', 'Pedix Store', false, 5);
            $out = $client->send('cust@example.test', 'Tu pedido B-000001', 'Hola! Tu pedido fue aceptado.');
            $this->assertSame(['ok' => true, 'error' => null], $out);
            $log = $server->awaitInLog('QUIT');
            foreach (['EHLO demo.test', 'AUTH LOGIN', base64_encode(self::USER), base64_encode(self::PASS),
                      'MAIL FROM:<orders@demo.test>', 'RCPT TO:<cust@example.test>', 'DATA', 'QUIT'] as $needle) {
                $this->assertTrue(str_contains($log, $needle), "transcript missing: $needle");
            }
            // DATA payload: Spanish-encoded headers + base64 body rendered correctly.
            [, $afterData] = explode("DATA\r\n", $log, 2);
            [$payload,] = explode("\r\n.\r\n", $afterData, 2);
            $this->assertTrue(str_contains($payload, 'Subject: =?UTF-8?B?' . base64_encode('Tu pedido B-000001') . '?='), 'encoded subject missing');
            $this->assertTrue(str_contains($payload, 'From: =?UTF-8?B?' . base64_encode('Pedix Store') . '?= <orders@demo.test>'), 'encoded from missing');
            $this->assertTrue(str_contains($payload, 'To: <cust@example.test>'), 'to header missing');
            $this->assertTrue(str_contains($payload, 'Content-Type: text/plain; charset=UTF-8'), 'charset header missing');
            $body64 = '';
            foreach (explode("\r\n", $payload) as $line) if (preg_match('#^[A-Za-z0-9+/=]+$#', $line) === 1) $body64 .= $line;
            $this->assertSame('Hola! Tu pedido fue aceptado.', base64_decode($body64));
        } finally { $server->stop(); }
    }

    public function testSmtpAuthRejectionIsTypedWithoutLeakingCredentials(): void
    {
        $server = new FakeSmtpServer([
            ['reply' => "220 stub ESMTP\r\n"],
            ['read' => 1, 'reply' => "250-stub\r\n250 AUTH LOGIN\r\n"],
            ['read' => 1, 'reply' => "535 5.7.8 authentication credentials rejected\r\n", 'close' => 1],
        ]);
        try {
            $server->start();
            $client = new SmtpClient('127.0.0.1', $server->port(), self::USER, self::PASS, 'orders@demo.test', 'Pedix', false, 5);
            $out = $client->send('cust@example.test', 'S', 'B');
            $this->assertSame(false, $out['ok']);
            $this->assertTrue(is_string($out['error']) && $out['error'] !== '', 'expected non-empty error');
            $this->assertTrue(str_contains($out['error'], 'auth'), 'error should name the failing stage: ' . $out['error']);
            $this->assertTrue(!str_contains($out['error'], self::USER) && !str_contains($out['error'], self::PASS), 'credentials leaked in error');
        } finally { $server->stop(); }
    }

    public function testSmtpProtocolFailureAtGreetingIsTyped(): void
    {
        $server = new FakeSmtpServer([['reply' => "554 5.7.0 service refused\r\n", 'close' => 1]]);
        try {
            $server->start();
            $client = new SmtpClient('127.0.0.1', $server->port(), self::USER, self::PASS, 'orders@demo.test', 'Pedix', false, 5);
            $out = $client->send('cust@example.test', 'S', 'B');
            $this->assertSame(false, $out['ok']);
            $this->assertTrue(str_contains((string) $out['error'], 'greeting'), 'error should name the stage: ' . $out['error']);
        } finally { $server->stop(); }
    }

    public function testSmtpConnectRefusedIsTypedAndNeverThrows(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe); // closed port: connection refused
        $client = new SmtpClient('127.0.0.1', $port, self::USER, self::PASS, 'orders@demo.test', 'Pedix', false, 2);
        $out = $client->send('cust@example.test', 'S', 'B');
        $this->assertSame(false, $out['ok']);
        $this->assertTrue(is_string($out['error']) && $out['error'] !== '');
        $this->assertTrue(!str_contains((string) $out['error'], self::PASS), 'password leaked in connect error');
    }

    public function testSmtpStarttlsAgainstPlainPeerIsTyped(): void
    {
        $server = new FakeSmtpServer([
            ['reply' => "220 stub ESMTP\r\n"],
            ['read' => 1, 'reply' => "250-stub\r\n250-STARTTLS\r\n250 AUTH LOGIN\r\n"],
            ['read' => 1, 'reply' => "220 go\r\n", 'close' => 1], // plaintext peer cannot handshake
        ]);
        try {
            $server->start();
            $client = new SmtpClient('127.0.0.1', $server->port(), self::USER, self::PASS, 'orders@demo.test', 'Pedix', true, 5);
            $out = $client->send('cust@example.test', 'S', 'B');
            $this->assertSame(false, $out['ok']);
            $this->assertTrue(str_contains((string) $out['error'], 'starttls'), 'error: ' . $out['error']);
        } finally { $server->stop(); }
    }

    public function testSmtpUnresponsiveServerTimesOutTyped(): void
    {
        $server = new FakeSmtpServer([['stall' => 12]]); // accepts, never replies
        try {
            $server->start();
            $client = new SmtpClient('127.0.0.1', $server->port(), self::USER, self::PASS, 'orders@demo.test', 'Pedix', false, 2);
            $start = microtime(true);
            $out = $client->send('cust@example.test', 'S', 'B');
            $this->assertSame(false, $out['ok']);
            $this->assertTrue(str_contains((string) $out['error'], 'timeout'), 'error: ' . $out['error']);
            $this->assertTrue(microtime(true) - $start < 8, 'timeout did not bound the send');
        } finally { $server->stop(); }
    }

    public function testWhatsappPostsPayloadWithTokenAndAccepts2xx(): void
    {
        $captured = null;
        $client = new WhatsAppClient('http://127.0.0.1:39999/bridge/send', 'wa_secret_token', 5,
            function (string $url, array $headers, string $payload) use (&$captured): array {
                $captured = ['url' => $url, 'headers' => $headers, 'payload' => $payload];
                return ['status' => 200, 'body' => '{"sent":true}'];
            });
        $out = $client->send('+549115555000', 'ignored subject', 'Tu pedido esta listo');
        $this->assertSame(['ok' => true, 'error' => null], $out);
        $this->assertSame('http://127.0.0.1:39999/bridge/send', $captured['url']);
        $this->assertSame('Authorization: Bearer wa_secret_token', $captured['headers'][0]);
        $this->assertSame('Content-Type: application/json', $captured['headers'][1]);
        $this->assertSame(['to' => '+549115555000', 'message' => 'Tu pedido esta listo'], json_decode($captured['payload'], true));
    }

    public function testWhatsappNon2xxAndExecutorThrowAreTypedWithoutLeakingUrlOrToken(): void
    {
        $client = new WhatsAppClient('http://127.0.0.1:39999/bridge/send', 'wa_secret_token', 5,
            fn(): array => ['status' => 500, 'body' => 'boom']);
        $out = $client->send('+549115555000', '', 'hola');
        $this->assertSame(false, $out['ok']);
        $this->assertSame('whatsapp bridge error HTTP 500', $out['error']);
        $throwing = new WhatsAppClient('http://127.0.0.1:39999/bridge/send', 'wa_secret_token', 5,
            fn(): array => throw new \RuntimeException('bridge exploded'));
        $out2 = $throwing->send('+549115555000', '', 'hola');
        $this->assertSame(false, $out2['ok']);
        foreach ([$out['error'], $out2['error']] as $error) {
            $this->assertTrue(is_string($error) && $error !== '');
            $this->assertTrue(!str_contains($error, 'wa_secret_token'), 'token leaked');
            $this->assertTrue(!str_contains($error, 'bridge/send'), 'URL leaked');
        }
    }

    public function testWhatsappUnresponsiveBridgeTimesOutTyped(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($listener === false) $this->assertTrue(false, "cannot bind listener: $errstr");
        try {
            $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
            $client = new WhatsAppClient("http://127.0.0.1:$port/send", 'wa_secret_token', 1); // nobody answers
            $start = microtime(true);
            $out = $client->send('+549115555000', '', 'hola');
            $this->assertSame(false, $out['ok']);
            $this->assertTrue(is_string($out['error']) && $out['error'] !== '');
            $this->assertTrue(!str_contains($out['error'], 'wa_secret_token') && !str_contains($out['error'], (string) $port), 'endpoint info leaked');
            $this->assertTrue(microtime(true) - $start < 4, 'timeout did not bound the send');
        } finally { fclose($listener); }
    }

    public function testMigration010SchemaPins(): void
    {
        $s = ScratchDatabase::create('vo_notif11_test_'); if ($s === null) return;
        try {
            $runner = new MigrationRunner($s->connection(), dirname(__DIR__) . '/api/database/migrations');
            $this->assertSame(['001 create_baseline', '002 create_auth_runtime', '003 create_catalog', '004 create_pricing_promotions', '005 create_cart', '006 create_orders', '007 create_payments', '008 operations', '009 delivery', '010 notifications'], $runner->run());
            $this->assertSame([], $runner->run()); // idempotent
            $this->assertTrue(in_array('notification_events', $s->tables(), true), 'notification_events missing');
            $cols = [];
            foreach ($s->pdo->query('SHOW COLUMNS FROM notification_events')->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[$c['Field']] = $c;
            foreach (['id','business_id','event','channel','recipient','subject','context_json','state','attempts','last_error','created_at','sent_at'] as $f) $this->assertTrue(isset($cols[$f]), "notification_events.$f missing");
            $this->assertSame("enum('email','whatsapp')", strtolower($cols['channel']['Type']));
            $this->assertSame("enum('pending','sent','failed')", strtolower($cols['state']['Type']));
            $this->assertSame('varchar(64)', strtolower($cols['event']['Type']));
            $this->assertSame('varchar(190)', strtolower($cols['recipient']['Type']));
            $this->assertSame('varchar(500)', strtolower($cols['last_error']['Type']));
            foreach (['event','recipient','business_id','state','attempts','created_at'] as $f) $this->assertSame('NO', $cols[$f]['Null'], "$f must be NOT NULL");
            foreach (['subject','context_json','last_error','sent_at'] as $f) $this->assertSame('YES', $cols[$f]['Null'], "$f must be NULLable");
            $this->assertSame('pending', $cols['state']['Default']);
            $this->assertSame('0', (string) $cols['attempts']['Default']);
            // MariaDB renders the CURRENT_TIMESTAMP default as current_timestamp().
            $this->assertTrue(str_starts_with(strtolower((string) $cols['created_at']['Default']), 'current_timestamp'), 'created_at must default to CURRENT_TIMESTAMP');
            $keys = [];
            foreach ($s->pdo->query('SHOW INDEX FROM notification_events')->fetchAll(PDO::FETCH_ASSOC) as $k) $keys[$k['Key_name']] = true;
            foreach (['idx_notification_events_business_state','idx_notification_events_event','idx_notification_events_created_at'] as $k) $this->assertTrue(isset($keys[$k]), "index $k missing");
            $fk = $s->pdo->query("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_notification_events_business'")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('CASCADE', $fk === false ? '' : (string) $fk['DELETE_RULE'], 'FK cascade rule missing');
            // Behavioral pins: defaults on minimal insert, channel enum guard, FK cascade.
            $s->pdo->exec("INSERT INTO businesses (name,slug) VALUES ('Demo','demo')");
            $s->pdo->exec("INSERT INTO notification_events (business_id,event,channel,recipient) VALUES (1,'order.created','email','c@example.test')");
            $row = $s->pdo->query('SELECT * FROM notification_events WHERE id=1')->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('pending', $row['state']);
            $this->assertSame(0, (int) $row['attempts']);
            foreach (['subject','context_json','last_error','sent_at'] as $f) $this->assertSame(null, $row[$f], "$f must default to NULL");
            $this->assertTrue($row['created_at'] !== null && $row['created_at'] !== '', 'created_at must default to now');
            // Channel/state value guards are pinned structurally via SHOW COLUMNS above: this
            // MariaDB runs non-strict sql_mode, so invalid ENUM values truncate with a
            // warning instead of erroring and cannot be pinned behaviorally here.
            $s->pdo->exec('DELETE FROM businesses WHERE id=1');
            $this->assertSame(0, (int) $s->pdo->query('SELECT COUNT(*) FROM notification_events')->fetchColumn(), 'rows must cascade with the business');
        } finally { $s->drop(); }
    }
}

/**
 * Scripted fake SMTP server for the SmtpClient dialogue tests: a separate PHP
 * process binds an OS-assigned loopback port, plays the given steps (reply /
 * read one line / read the DATA payload until the dot / stall), and logs every
 * received line to a transcript file. Mirrors the FakeMpServer harness style.
 */
final class FakeSmtpServer
{
    private $process = null;
    private int $port = 0;
    private string $directory;

    public function __construct(private array $steps)
    {
        $this->directory = sys_get_temp_dir() . '/vo_fake_smtp_' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        file_put_contents($this->directory . '/worker.php', $this->worker());
        file_put_contents($this->directory . '/spec.json', json_encode([
            'steps' => $this->steps,
            'log_file' => $this->directory . '/log.txt',
            'port_file' => $this->directory . '/port.txt',
        ], JSON_UNESCAPED_SLASHES));
    }

    public function start(): void
    {
        $this->process = proc_open(
            [PHP_BINARY, $this->directory . '/worker.php', $this->directory . '/spec.json'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->directory
        );
        if (!is_resource($this->process)) throw new \RuntimeException('Fake SMTP worker did not start.');
        $deadline = microtime(true) + 5;
        do {
            $port = @file_get_contents($this->directory . '/port.txt');
            if ($port !== false && (int) $port > 0) { $this->port = (int) $port; return; }
            usleep(50000);
        } while (microtime(true) < $deadline);
        $this->stop();
        throw new \RuntimeException('Fake SMTP worker never reported its port.');
    }

    public function port(): int { return $this->port; }

    /** Transcript polling: the worker flushes on close, so wait for the last expected line. */
    public function awaitInLog(string $needle, float $timeoutSec = 3): string
    {
        $deadline = microtime(true) + $timeoutSec;
        do {
            $log = @file_get_contents($this->directory . '/log.txt');
            if ($log !== false && str_contains($log, $needle)) return $log;
            usleep(50000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException("transcript never contained: $needle");
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if (($status['pid'] ?? 0) > 0 && PHP_OS_FAMILY === 'Windows') {
                @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' >NUL 2>&1');
            } else {
                proc_terminate($this->process);
            }
            proc_close($this->process);
            $this->process = null;
        }
    }

    public function __destruct() { $this->stop(); }

    private function worker(): string
    {
        return <<<'PHP'
<?php
$spec = json_decode(file_get_contents($argv[1]), true);
$log = fopen($spec['log_file'], 'ab');
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) { fwrite(STDERR, "bind failed: $errstr"); exit(1); }
$addr = (string) stream_socket_get_name($server, false);
file_put_contents($spec['port_file'], (string) (int) substr(strrchr($addr, ':'), 1));
$client = stream_socket_accept($server, 15);
if ($client === false) exit(1);
stream_set_timeout($client, 20);
foreach ($spec['steps'] as $step) {
    if (!empty($step['stall'])) { sleep((int) $step['stall']); continue; }
    if (!empty($step['payload'])) {
        while (true) {
            $line = fgets($client, 4096);
            if ($line === false) break 2;
            fwrite($log, $line);
            if (rtrim($line, "\r\n") === '.') break;
        }
    } elseif (!empty($step['read'])) {
        $line = fgets($client, 4096);
        if ($line === false) break;
        fwrite($log, $line);
    }
    if (isset($step['reply'])) fwrite($client, $step['reply']);
    if (!empty($step['close'])) break;
}
fclose($client); fclose($server); fclose($log);
PHP;
    }
}
