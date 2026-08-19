<?php declare(strict_types=1);
namespace Tests;
use VO\Domain\IdempotencyOutcome; use VO\Domain\InMemoryIdempotencyStore;

final class IdempotencyTest extends TestCase
{
    public function testDuplicateAttemptReturnsFirstOutcomeMarker(): void
    {
        $store = new InMemoryIdempotencyStore(); $runs = 0;
        $first = $store->attempt('k1', function () use (&$runs): IdempotencyOutcome { $runs++; return new IdempotencyOutcome('first', 10); });
        $second = $store->attempt('k1', function () use (&$runs): IdempotencyOutcome { $runs++; return new IdempotencyOutcome('second', 20); });
        $this->assertSame(1, $runs); $this->assertSame('first', $first->marker()); $this->assertSame('first', $second->marker()); $this->assertSame(10, $second->value());
    }
}
