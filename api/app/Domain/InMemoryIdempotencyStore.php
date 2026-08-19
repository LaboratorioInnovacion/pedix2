<?php declare(strict_types=1);
namespace VO\Domain;
final class InMemoryIdempotencyStore implements IdempotencyStore
{
    private array $outcomes = [];
    public function attempt(string $key, callable $work): IdempotencyOutcome { return $this->outcomes[$key] ??= $work(); }
}
