<?php

declare(strict_types=1);

namespace Tests;

use RuntimeException;
use VO\Config\Config;

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
}
