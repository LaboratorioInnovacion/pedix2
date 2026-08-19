<?php declare(strict_types=1);
namespace Tests;
use VO\Domain\InvalidTransition; use VO\Domain\StateMachine; use VO\Domain\TransitionHistoryHook;

final class StateMachineTest extends TestCase
{
    public function testTransitionsAndHistoryHook(): void
    {
        $hook = new RecordingHook();
        $machine = new StateMachine(['draft', 'published'], ['draft' => ['published']], $hook);
        $this->assertTrue($machine->can('draft', 'published')); $this->assertSame('published', $machine->transition('draft', 'published')); $this->assertSame([['draft', 'published']], $hook->records);
    }

    public function testInvalidTransitionThrows(): void
    {
        $machine = new StateMachine(['a', 'b'], ['a' => []]);
        $this->assertThrows(InvalidTransition::class, static fn () => $machine->transition('a', 'b'));
    }
}

final class RecordingHook implements TransitionHistoryHook
{
    public array $records = [];
    public function record(string $from, string $to): void { $this->records[] = [$from, $to]; }
}
