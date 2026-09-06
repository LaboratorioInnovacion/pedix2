<?php declare(strict_types=1);
namespace Tests;

use PDO;
use VO\Audit\AuditService;
use VO\Database\MigrationRunner;
use VO\Orders\OrderRepository;
use VO\Payments\MpApiException;
use VO\Payments\MpClient;
use VO\Payments\PaymentRepository;
use VO\Payments\PaymentService;

final class MpWebhookHttpTest extends TestCase
{
    public function testPreferenceCreationStoresProviderData(): void
    {
        $database = $this->database();
        $provider = $this->provider([]);
        try {
            $orderId = $this->order($database);
            $database->pdo->exec("INSERT INTO order_items (order_id,branch_id,item_id,item_name,quantity,unit_price_cents,modifiers_total_cents,gross_unit_cents,gross_line_cents,line_total_cents) VALUES ($orderId,1,1,'Burger',2,750,0,750,1500,1500)");
            $service = $this->service($database, $provider);

            $payment = $service->initiateForOrder($orderId, 'mercadopago');

            $this->assertSame('pending', $payment['state']);
            $this->assertSame('pref-1', $payment['provider_preference_id']);
            $this->assertSame('http://fake.test/init', $payment['preference_init_point']);
            $this->assertSame((string) $payment['id'], $payment['external_reference']);
        } finally {
            $provider->stop();
            $database->drop();
        }
    }

    public function testApprovedWebhookConfirmsProviderSanitizesEventAndAcceptsOrder(): void
    {
        $database = $this->database();
        $provider = $this->provider(['pay-approved' => [
            'id' => 'pay-approved',
            'status' => 'approved',
            'status_detail' => 'accredited',
            'external_reference' => '1',
            'payer' => ['email' => 'private@example.test'],
            'access_token' => 'must-not-be-stored',
        ]]);
        [$server] = $this->harness($database, $provider);
        try {
            $orderId = $this->order($database);
            $paymentId = $this->payment($database, $orderId);

            $response = $server->json('POST', '/api/webhooks/mercadopago', $this->body('event-approved', 'pay-approved'), $this->signature('pay-approved'));

            $this->assertSame(200, $response['status'], $response['body']);
            $this->assertSame('approved', (string) $database->pdo->query("SELECT state FROM payments WHERE id=$paymentId")->fetchColumn());
            $this->assertSame('accepted', (string) $database->pdo->query("SELECT status FROM orders WHERE id=$orderId")->fetchColumn());
            $event = $database->pdo->query("SELECT provider_event_id,result_state,payload_json FROM payment_events WHERE provider='mercadopago'")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('event-approved', $event['provider_event_id']);
            $this->assertSame('approved', $event['result_state']);
            $this->assertTrue(!str_contains((string) $event['payload_json'], 'private@example.test'));
            $this->assertTrue(!str_contains((string) $event['payload_json'], 'must-not-be-stored'));
        } finally {
            $server->stop();
            $provider->stop();
            $database->drop();
        }
    }

    public function testDuplicateWebhookReturnsSuccessWithoutDuplicateTransition(): void
    {
        $database = $this->database();
        $provider = $this->provider(['pay-duplicate' => ['id' => 'pay-duplicate', 'status' => 'approved', 'external_reference' => '1']]);
        [$server] = $this->harness($database, $provider);
        try {
            $orderId = $this->order($database);
            $this->payment($database, $orderId);
            $body = $this->body('event-duplicate', 'pay-duplicate');
            $headers = $this->signature('pay-duplicate');

            $this->assertSame(200, $server->json('POST', '/api/webhooks/mercadopago', $body, $headers)['status']);
            $this->assertSame(200, $server->json('POST', '/api/webhooks/mercadopago', $body, $headers)['status']);

            $this->assertSame(1, (int) $database->pdo->query("SELECT COUNT(*) FROM payment_events WHERE provider='mercadopago'")->fetchColumn());
            $this->assertSame(1, (int) $database->pdo->query("SELECT COUNT(*) FROM payment_events WHERE event_type='payment.approved'")->fetchColumn());
            $this->assertSame('accepted', (string) $database->pdo->query("SELECT status FROM orders WHERE id=$orderId")->fetchColumn());
        } finally {
            $server->stop();
            $provider->stop();
            $database->drop();
        }
    }

    public function testInvalidSignatureReturnsUnauthorizedWithoutProviderTransition(): void
    {
        $database = $this->database();
        $provider = $this->provider(['pay-forged' => ['id' => 'pay-forged', 'status' => 'approved', 'external_reference' => '1']]);
        [$server] = $this->harness($database, $provider);
        try {
            $this->payment($database, $this->order($database));

            $response = $server->json('POST', '/api/webhooks/mercadopago', $this->body('event-forged', 'pay-forged'), [
                'x-request-id' => 'request-forged',
                'x-signature' => 'ts=123,v1=invalid',
            ]);

            $this->assertSame(401, $response['status']);
            $this->assertSame('pending', (string) $database->pdo->query('SELECT state FROM payments LIMIT 1')->fetchColumn());
            $this->assertSame(0, (int) $database->pdo->query('SELECT COUNT(*) FROM payment_events')->fetchColumn());
        } finally {
            $server->stop();
            $provider->stop();
            $database->drop();
        }
    }

    public function testPendingWebhookPersistsPendingWithoutAcceptingOrder(): void
    {
        $database = $this->database();
        $provider = $this->provider(['pay-pending' => ['id' => 'pay-pending', 'status' => 'pending', 'external_reference' => '1']]);
        [$server] = $this->harness($database, $provider);
        try {
            $orderId = $this->order($database);
            $this->payment($database, $orderId);

            $response = $server->json('POST', '/api/webhooks/mercadopago', $this->body('event-pending', 'pay-pending'), $this->signature('pay-pending'));

            $this->assertSame(200, $response['status']);
            $this->assertSame('pending', (string) $database->pdo->query('SELECT state FROM payments LIMIT 1')->fetchColumn());
            $this->assertSame('pending', (string) $database->pdo->query("SELECT status FROM orders WHERE id=$orderId")->fetchColumn());
            $this->assertSame('pending', (string) $database->pdo->query("SELECT result_state FROM payment_events WHERE provider='mercadopago'")->fetchColumn());
        } finally {
            $server->stop();
            $provider->stop();
            $database->drop();
        }
    }

    public function testRejectedWebhookRejectsPaymentWithoutAcceptingOrder(): void
    {
        $database = $this->database();
        $provider = $this->provider(['pay-rejected' => ['id' => 'pay-rejected', 'status' => 'rejected', 'external_reference' => '1']]);
        [$server] = $this->harness($database, $provider);
        try {
            $orderId = $this->order($database);
            $this->payment($database, $orderId);

            $response = $server->json('POST', '/api/webhooks/mercadopago', $this->body('event-rejected', 'pay-rejected'), $this->signature('pay-rejected'));

            $this->assertSame(200, $response['status']);
            $this->assertSame('rejected', (string) $database->pdo->query('SELECT state FROM payments LIMIT 1')->fetchColumn());
            $this->assertSame('pending', (string) $database->pdo->query("SELECT status FROM orders WHERE id=$orderId")->fetchColumn());
        } finally {
            $server->stop();
            $provider->stop();
            $database->drop();
        }
    }

    public function testUnknownReferenceIsAuditedWithoutChangingKnownPayment(): void
    {
        $database = $this->database();
        $provider = $this->provider(['pay-unknown' => ['id' => 'pay-unknown', 'status' => 'approved', 'external_reference' => '999999']]);
        [$server] = $this->harness($database, $provider);
        try {
            $orderId = $this->order($database);
            $this->payment($database, $orderId);

            $response = $server->json('POST', '/api/webhooks/mercadopago', $this->body('event-unknown', 'pay-unknown'), $this->signature('pay-unknown'));

            $this->assertSame(200, $response['status']);
            $this->assertSame('pending', (string) $database->pdo->query('SELECT state FROM payments LIMIT 1')->fetchColumn());
            $this->assertSame('pending', (string) $database->pdo->query("SELECT status FROM orders WHERE id=$orderId")->fetchColumn());
            $this->assertSame(1, (int) $database->pdo->query("SELECT COUNT(*) FROM payment_events WHERE provider_event_id='event-unknown' AND payment_id IS NULL")->fetchColumn());
        } finally {
            $server->stop();
            $provider->stop();
            $database->drop();
        }
    }

    public function testProviderFailureKeepsPaymentPendingAndTypedErrorDoesNotLeakToken(): void
    {
        $database = $this->database();
        $provider = $this->provider([]);
        [$server] = $this->harness($database, $provider);
        try {
            $this->payment($database, $this->order($database));

            $response = $server->json('POST', '/api/webhooks/mercadopago', $this->body('event-missing', 'pay-missing'), $this->signature('pay-missing'));

            $this->assertSame(200, $response['status']);
            $this->assertSame('pending', (string) $database->pdo->query('SELECT state FROM payments LIMIT 1')->fetchColumn());
            $this->assertSame(0, (int) $database->pdo->query('SELECT COUNT(*) FROM payment_events')->fetchColumn());

            try {
                (new MpClient($provider->url(), 'token'))->getPayment('pay-missing');
                throw new \RuntimeException('Expected MpApiException.');
            } catch (MpApiException $exception) {
                $this->assertSame(404, $exception->statusCode);
                $this->assertTrue(!str_contains($exception->getMessage(), 'token'));
                $this->assertTrue(!str_contains($exception->getMessage(), 'Bearer'));
            }
        } finally {
            $server->stop();
            $provider->stop();
            $database->drop();
        }
    }

    private function database(): ScratchDatabase
    {
        $database = ScratchDatabase::create('vo_pay8_test_');
        (new MigrationRunner($database->connection(), dirname(__DIR__) . '/api/database/migrations'))->run();
        $database->pdo->exec("INSERT INTO businesses (id,name,slug) VALUES (1,'Demo','demo')");
        $database->pdo->exec("INSERT INTO users (id,business_id,name,email,password_hash) VALUES (1,1,'Admin','admin@example.test','hash')");
        $database->pdo->exec("INSERT INTO branches (id,business_id,name,is_active) VALUES (1,1,'Main',1)");
        $database->pdo->exec("INSERT INTO categories (id,name,slug) VALUES (1,'Food','food')");
        $database->pdo->exec("INSERT INTO catalog_items (id,category_id,type,name,slug,base_price_cents,is_active) VALUES (1,1,'product','Burger','burger',1500,1)");
        $database->pdo->exec("INSERT INTO business_settings (business_id,setting_key,setting_value) VALUES (1,'mp_enabled','1'),(1,'mp_access_token','token'),(1,'mp_webhook_secret','secret')");
        return $database;
    }

    private function service(ScratchDatabase $database, FakeMpServer $provider): PaymentService
    {
        $connection = $database->connection();
        return new PaymentService(
            $connection,
            new PaymentRepository($connection),
            new OrderRepository($connection),
            new AuditService($database->pdo),
            'mp-preference',
            new MpClient($provider->url(), 'token'),
            'http://shop.test'
        );
    }

    private function order(ScratchDatabase $database): int
    {
        $number = 'B-' . bin2hex(random_bytes(3));
        $token = bin2hex(random_bytes(32));
        $database->pdo->exec("INSERT INTO orders (business_id,branch_id,number,public_token,fulfillment,payment_method,gross_items_cents,item_promotions_cents,order_promotions_cents,coupon_discount_cents,payment_discount_cents,merchandise_total_cents,delivery_fee_cents,grand_total_cents) VALUES (1,1," . $database->pdo->quote($number) . "," . $database->pdo->quote($token) . ",'pickup','mercadopago',1500,0,0,0,0,1500,0,1500)");
        return (int) $database->pdo->lastInsertId();
    }

    private function payment(ScratchDatabase $database, int $orderId): int
    {
        $database->pdo->exec("INSERT INTO payments (order_id,business_id,method,state,amount_cents,currency,external_reference) VALUES ($orderId,1,'mercadopago','pending',1500,'ARS','1')");
        return (int) $database->pdo->lastInsertId();
    }

    private function body(string $eventId, string $paymentId): array
    {
        return ['id' => $eventId, 'type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => $paymentId]];
    }

    private function signature(string $paymentId): array
    {
        $timestamp = '123';
        $requestId = 'request-' . $paymentId;
        $manifest = 'id:' . strtolower($paymentId) . ';request-id:' . $requestId . ';ts:' . $timestamp . ';';
        return [
            'x-request-id' => $requestId,
            'x-signature' => 'ts=' . $timestamp . ',v1=' . hash_hmac('sha256', $manifest, 'secret'),
        ];
    }

    private function harness(ScratchDatabase $database, FakeMpServer $provider): array
    {
        $directory = $this->tempDir('mp_webhook');
        $installedConfig = $directory . '/installed.php';
        $config = ['database' => [
            'dsn' => "mysql:host=127.0.0.1;port=3306;dbname=$database->name;charset=utf8mb4",
            'user' => 'root',
            'password' => '',
        ]];
        file_put_contents($installedConfig, '<?php return ' . var_export($config, true) . ';');
        $server = new CartApiServer(dirname(__DIR__) . '/public_html', [
            'VO_INSTALLED_CONFIG_PATH' => $installedConfig,
            'VO_STORAGE_PATH' => $directory . '/storage',
            'VO_HTTP_NO_REDIRECTS' => '1',
            'VO_MP_BASE_URL' => $provider->url(),
            'VO_PUBLIC_BASE_URL' => 'http://shop.test',
        ]);
        $server->start();
        return [$server, $installedConfig];
    }

    private function provider(array $payments): FakeMpServer
    {
        $provider = new FakeMpServer($payments);
        $provider->start();
        return $provider;
    }
}

final class FakeMpServer
{
    private $process = null;
    private int $port;
    private string $directory;

    public function __construct(private array $payments)
    {
        $this->port = random_int(20000, 45000);
        $this->directory = sys_get_temp_dir() . '/vo_fake_mp_' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        file_put_contents($this->directory . '/router.php', $this->router());
    }

    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function start(): void
    {
        putenv('VO_FAKE_MP_PAYMENTS=' . base64_encode(json_encode($this->payments)));
        $this->process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', $this->directory, $this->directory . '/router.php'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->directory
        );
        $deadline = microtime(true) + 5;
        do {
            if (@file_get_contents($this->url() . '/health') !== false) return;
            usleep(100000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('Fake MP server did not start.');
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
        }
        putenv('VO_FAKE_MP_PAYMENTS');
    }

    private function router(): string
    {
        return <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
header('Content-Type: application/json');
if ($path === '/health') { echo '{}'; return; }
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authorization !== 'Bearer token') { http_response_code(401); echo json_encode(['authorization' => $authorization]); return; }
if ($path === '/checkout/preferences') { echo json_encode(['id' => 'pref-1', 'init_point' => 'http://fake.test/init']); return; }
if (preg_match('#^/v1/payments/([^/]+)$#', $path, $matches)) {
    $payments = json_decode(base64_decode(getenv('VO_FAKE_MP_PAYMENTS') ?: ''), true) ?: [];
    if (isset($payments[$matches[1]])) { echo json_encode($payments[$matches[1]]); return; }
    http_response_code(404);
    echo json_encode(['authorization' => $authorization]);
    return;
}
http_response_code(404);
echo '{}';
PHP;
    }
}
