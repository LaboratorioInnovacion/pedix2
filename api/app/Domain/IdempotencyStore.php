<?php declare(strict_types=1);
namespace VO\Domain;
interface IdempotencyStore { public function attempt(string $key, callable $work): IdempotencyOutcome; }
