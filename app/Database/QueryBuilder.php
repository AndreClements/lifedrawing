<?php

declare(strict_types=1);

namespace App\Database;

/**
 * Fluent query builder — sugar over prepared statements.
 *
 * Not an ORM. No magic. Just a clean way to build SELECT/INSERT/UPDATE/DELETE
 * queries without string concatenation. Every value goes through parameters.
 */
final class QueryBuilder
{
    private string $table = '';
    private array $selects = ['*'];
    private array $wheres = [];
    private array $params = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private array $joins = [];
    private int $paramIndex = 0;

    public function __construct(
        private readonly Connection $db,
    ) {}

    public function table(string $table): self
    {
        $clone = clone $this;
        $clone->table = $table;
        return $clone;
    }

    public function select(string ...$columns): self
    {
        $clone = clone $this;
        $clone->selects = $columns;
        return $clone;
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        $clone = clone $this;
        $placeholder = ':w' . $clone->paramIndex++;
        $clone->wheres[] = "{$column} {$operator} {$placeholder}";
        $clone->params[$placeholder] = $value;
        return $clone;
    }

    public function whereIn(string $column, array $values): self
    {
        if (empty($values)) {
            // Empty IN clause — always false
            $clone = clone $this;
            $clone->wheres[] = '1 = 0';
            return $clone;
        }

        $clone = clone $this;
        $placeholders = [];
        foreach ($values as $v) {
            $placeholder = ':w' . $clone->paramIndex++;
            $placeholders[] = $placeholder;
            $clone->params[$placeholder] = $v;
        }
        $clone->wheres[] = "{$column} IN (" . implode(', ', $placeholders) . ')';
        return $clone;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $clone = clone $this;
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $clone->orderBy[] = "{$column} {$direction}";
        return $clone;
    }

    public function limit(int $limit): self
    {
        $clone = clone $this;
        $clone->limit = $limit;
        return $clone;
    }

    public function offset(int $offset): self
    {
        $clone = clone $this;
        $clone->offset = $offset;
        return $clone;
    }

    public function join(string $table, string $on, string $type = 'INNER'): self
    {
        $clone = clone $this;
        $clone->joins[] = "{$type} JOIN {$table} ON {$on}";
        return $clone;
    }

    public function leftJoin(string $table, string $on): self
    {
        return $this->join($table, $on, 'LEFT');
    }

    // --- Execution ---

    /** Fetch all matching rows. */
    public function get(): array
    {
        $sql = $this->buildSelect();
        return $this->db->fetchAll($sql, $this->params);
    }

    /** Fetch the first matching row. */
    public function first(): ?array
    {
        $sql = $this->limit(1)->buildSelect();
        return $this->db->fetch($sql, $this->params);
    }

    /** Count matching rows. */
    public function count(): int
    {
        $clone = clone $this;
        $clone->selects = ['COUNT(*) as cnt'];
        $clone->orderBy = [];
        $clone->limit = null;
        $clone->offset = null;
        $sql = $clone->buildSelect();
        $row = $this->db->fetch($sql, $clone->params);
        return (int) ($row['cnt'] ?? 0);
    }

    /** Insert a row and return the last insert ID. */
    public function insert(array $data): string
    {
        $columns = array_keys($data);
        $placeholders = [];
        $params = [];

        foreach ($data as $col => $val) {
            $placeholder = ':i' . $this->paramIndex++;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $val;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $columns),
            implode(', ', $placeholders),
        );

        $this->db->execute($sql, $params);
        return $this->db->lastInsertId();
    }

    /** Update matching rows. Returns affected row count. */
    public function update(array $data): int
    {
        $sets = [];
        $params = $this->params;

        foreach ($data as $col => $val) {
            $placeholder = ':u' . $this->paramIndex++;
            $sets[] = "{$col} = {$placeholder}";
            $params[$placeholder] = $val;
        }

        $sql = sprintf('UPDATE %s SET %s', $this->table, implode(', ', $sets));

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        return $this->db->execute($sql, $params);
    }

    /** Delete matching rows. Returns affected row count. */
    public function delete(): int
    {
        $sql = "DELETE FROM {$this->table}";

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        return $this->db->execute($sql, $this->params);
    }

    // --- SQL building ---

    private function buildSelect(): string
    {
        $sql = 'SELECT ' . implode(', ', $this->selects) . " FROM {$this->table}";

        foreach ($this->joins as $join) {
            $sql .= ' ' . $join;
        }

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        if (!empty($this->orderBy)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }

        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }

        return $sql;
    }
}
