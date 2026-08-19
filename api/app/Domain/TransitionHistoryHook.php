<?php declare(strict_types=1);
namespace VO\Domain;
interface TransitionHistoryHook { public function record(string $from, string $to): void; }
