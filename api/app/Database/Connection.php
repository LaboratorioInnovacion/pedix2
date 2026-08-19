<?php declare(strict_types=1);
namespace VO\Database;
interface Connection { public function select(string $sql, array $params = []): array; public function execute(string $sql, array $params = []): int; public function transaction(callable $fn): mixed; }
