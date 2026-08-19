<?php declare(strict_types=1);
namespace VO\Domain;
final class StateMachine
{
    public function __construct(private array $states, private array $transitions, private ?TransitionHistoryHook $hook = null) {}
    public function can(string $from, string $to): bool { return in_array($from, $this->states, true) && in_array($to, $this->transitions[$from] ?? [], true); }
    public function transition(string $from, string $to): string { if (!$this->can($from, $to)) throw new InvalidTransition($from . ' cannot transition to ' . $to); $this->hook?->record($from, $to); return $to; }
}
