<?php declare(strict_types=1);
namespace VO\Domain;
final class IdempotencyOutcome { public function __construct(private string $marker, private mixed $value = null) {} public function marker(): string { return $this->marker; } public function value(): mixed { return $this->value; } }
