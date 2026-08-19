<?php declare(strict_types=1);
namespace VO\Database;
use PDO; use RuntimeException; use Throwable;

final class PdoConnection implements Connection
{
    private ?object $pdo = null;

    public function __construct(
        private string $dsn,
        private string $user = '',
        private string $password = '',
        private array $options = [], private mixed $factory = null) {}

    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->db()->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->db()->prepare($sql); $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function transaction(callable $fn): mixed
    {
        $db = $this->db();
        if ($db->inTransaction()) throw new RuntimeException('Nested transactions are not allowed.');
        $db->beginTransaction();
        try {
            $result = $fn($this);
            if ($db->inTransaction()) $db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private function db(): object
    {
        if ($this->pdo === null) {
            $factory = $this->factory ?? static fn (string $dsn, string $user, string $password, array $options): object => new PDO($dsn, $user, $password, $options + [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
            $this->pdo = $factory($this->dsn, $this->user, $this->password, $this->options);
        }
        return $this->pdo;
    }
}
