<?php

declare(strict_types=1);

namespace Tests;

use RuntimeException;
use VO\Config\Config;
use VO\Http\JsonResponse;
use VO\Http\Request;
use VO\Http\Router;

final class FoundationRuntimeTest extends TestCase
{
    public function testPrivatePublicLayoutExists(): void
    {
        $root = dirname(__DIR__);
        foreach (['api/app', 'api/bootstrap', 'api/config', 'api/database/migrations', 'api/storage/logs', 'public_html'] as $path) {
            $this->assertTrue(is_dir($root . '/' . $path), $path . ' should exist.');
        }
        $this->assertTrue(!is_dir($root . '/public_html/api/app'), 'Private app must not be under public_html.');
    }

    public function testMissingConfigFailsClosedWithoutSecrets(): void
    {
        $this->assertThrows(RuntimeException::class, static fn () => Config::load([
            'config_root' => __DIR__ . '/missing-config',
        ], ['app']));
    }

    public function testPostDispatchWrongMethodAndFormHelper(): void
    {
        $router = new Router();
        $router->post('/submit', static fn (Request $request): JsonResponse => JsonResponse::ok(['name' => $request->post('name')]));
        $response = $router->dispatch(new Request('POST', '/submit', [], [], [], ['name' => 'Demo']));
        $this->assertSame(200, $response->status());
        $this->assertTrue(str_contains($response->body(), 'Demo'));
        $this->assertSame(405, $router->dispatch(new Request('GET', '/submit'))->status());
    }

    public function testJsonGetBehaviorUnchanged(): void
    {
        $router = new Router();
        $router->get('/health', static fn (Request $request): JsonResponse => JsonResponse::ok(['status' => 'ok'], 'rid'));
        $response = $router->dispatch(new Request('GET', '/health'));
        $this->assertSame(200, $response->status());
        $this->assertSame('application/json; charset=utf-8', $response->headers()['Content-Type']);
        $this->assertTrue(str_contains($response->body(), '"ok":true'));
    }
}
