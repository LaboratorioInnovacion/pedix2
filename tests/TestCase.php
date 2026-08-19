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
}
