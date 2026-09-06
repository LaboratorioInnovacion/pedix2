<?php
declare(strict_types=1);
namespace Tests;
use Throwable;
abstract class TestCase
{
    protected function assertTrue(bool $actual, string $message = 'Expected true.'): void { if (!$actual) throw new \RuntimeException($message); }
    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) throw new \RuntimeException($message ?: 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    protected function assertThrows(string $class, callable $fn): void
    {
        try { $fn(); } catch (Throwable $e) { if ($e instanceof $class) return; throw new \RuntimeException('Expected ' . $class . ', got ' . $e::class); }
        throw new \RuntimeException('Expected exception ' . $class);
    }
    protected function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        if ($unexpected === $actual) throw new \RuntimeException($message ?: 'Unexpected value ' . var_export($actual, true));
    }
    protected function tempDir(string $name): string
    {
        $dir = sys_get_temp_dir() . '/vo_' . $name . '_' . bin2hex(random_bytes(4));
        if (!mkdir($dir) && !is_dir($dir)) throw new \RuntimeException('Could not create temp dir.');
        return $dir;
    }
}
