<?php declare(strict_types=1);
namespace Tests;
use RuntimeException; use VO\Database\PdoConnection;

final class PdoConnectionTest extends TestCase
{
    public function testConnectsLazilyAndUsesPreparedParameters(): void
    {
        $created = 0; $fake = new FakePdo();
        $db = new PdoConnection('dsn', factory: function () use (&$created, $fake): object { $created++; return $fake; });
        $this->assertSame(0, $created); $db->select('SELECT * FROM things WHERE id = ?', [7]); $this->assertSame(1, $created);
        $this->assertSame(['SELECT * FROM things WHERE id = ?'], $fake->prepared); $this->assertSame([7], $fake->lastParams);
    }

    public function testTransactionRollsBackOnThrowableAndRefusesNested(): void
    {
        $fake = new FakePdo(); $db = new PdoConnection('dsn', factory: static fn (): object => $fake);
        $this->assertThrows(RuntimeException::class, static fn () => $db->transaction(static function (): void { throw new RuntimeException('boom'); }));
        $this->assertSame(['begin', 'rollback'], $fake->tx);
        $fake->inTransaction = true;
        $this->assertThrows(RuntimeException::class, static fn () => $db->transaction(static fn () => null));
    }

    public function testTransactionToleratesImplicitCommitBeforeBoundaryCommit(): void
    {
        $fake = new FakePdo(); $db = new PdoConnection('dsn', factory: static fn (): object => $fake);
        $db->transaction(static function () use ($fake): string { $fake->inTransaction = false; return 'ok'; });
        $this->assertSame(['begin'], $fake->tx);
    }
}

final class FakePdo
{
    public array $prepared = [], $tx = [], $lastParams = []; public bool $inTransaction = false;
    public function prepare(string $sql): FakeStatement { $this->prepared[] = $sql; return new FakeStatement($this); }
    public function beginTransaction(): void { $this->tx[] = 'begin'; $this->inTransaction = true; }
    public function commit(): void { $this->tx[] = 'commit'; $this->inTransaction = false; }
    public function rollBack(): void { $this->tx[] = 'rollback'; $this->inTransaction = false; }
    public function inTransaction(): bool { return $this->inTransaction; }
}

final class FakeStatement
{
    public function __construct(private FakePdo $pdo) {} public function execute(array $params = []): void { $this->pdo->lastParams = $params; }
    public function fetchAll(int $mode): array { return [['id' => 7]]; } public function rowCount(): int { return 1; }
}
