<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOStatement;

/**
 * PDO wrapper with prepared-statement-only access.
 *
 * No raw SQL concatenation is ever permitted — every query goes through
 * prepare/execute. This is the Safety facet of the Octagon made executable.
 */
final class Connection
{
    private PDO $pdo;
    private int $transactionDepth = 0;

    public function __construct(
        string $host,
        string $database,
        string $username,
        string $password,
        int $port = 3306,
        string $charset = 'utf8mb4',
    ) {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,  // Real prepared statements
            PDO::ATTR_STRINGIFY_FETCHES  => false,   // Preserve types
        ]);
    }

    /** Execute a prepared statement and return it. */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Fetch all rows. */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** Fetch a single row. */
    public function fetch(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }

    /** Fetch a single column value. */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    /** Execute and return the number of affected rows. */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /** Get the last inserted ID. */
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /** Run a callback inside a transaction. */
    public function transaction(callable $callback): mixed
    {
        $depth = $this->transactionDepth;
        $savepoint = 'ldr_tx_' . $depth;
        if ($depth === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec("SAVEPOINT {$savepoint}");
        }
        $this->transactionDepth++;
        try {
            $result = $callback($this);
            if ($depth === 0) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                if ($depth === 0) {
                    $this->pdo->rollBack();
                } else {
                    $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                    $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
                }
            }
            throw $e;
        } finally {
            $this->transactionDepth = $depth;
        }
    }

    /** Get the raw PDO (escape hatch — use sparingly). */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
