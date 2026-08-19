<?php
declare(strict_types=1);
namespace Tests;
use VO\Http\JsonResponse;
use VO\Http\Request;
final class HttpSmokeTest extends TestCase
{
    private function request(string $method, string $path): JsonResponse
    {
        $app = require dirname(__DIR__) . '/api/bootstrap/app.php';
        return $app(new Request($method, $path));
    }
    public function testHealthRouteReturnsSafeJsonAndHeaders(): void
    {
        $response = $this->request('GET', '/health');
        $body = json_decode($response->body(), true);
        $headers = $response->headers();
        $this->assertSame(200, $response->status());
        $this->assertSame(true, $body['ok']);
        $this->assertSame('ok', $body['data']['status']);
        $this->assertTrue((bool) $body['request_id']);
        $this->assertTrue(str_contains($headers['Content-Security-Policy'], "frame-ancestors 'none'"));
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('no-referrer', $headers['Referrer-Policy']);
        $this->assertTrue(isset($headers['Permissions-Policy']));
    }
    public function testNotFoundAndMethodNotAllowed(): void
    {
        $this->assertSame(404, $this->request('GET', '/missing')->status());
        $this->assertSame(405, $this->request('POST', '/health')->status());
    }
}
