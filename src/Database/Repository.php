<?php

declare(strict_types=1);

namespace bapersbw\Database;

/**
 * Base class for module data access. A repository owns one table and offers
 * simple CRUD returning plain arrays (not an ORM). Subclasses declare the table
 * and, if needed, the primary key.
 *
 * The SQL is portable across MSSQL and sqlite; use the {@see Connection}
 * directly for anything more involved.
 *
 * Repositories are autowired (they only need a Connection), so a module's
 * webservice can declare one in its constructor.
 */
abstract class Repository
{
    protected string $primaryKey = 'id';

    public function __construct(
        protected readonly Connection $db,
    ) {
    }

    /** The table this repository operates on. */
    abstract protected function table(): string;

    /** @return array<string, mixed>|null */
    public function find(int|string $id): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM {$this->table()} WHERE {$this->primaryKey} = ?",
            [$id],
        );
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select("SELECT * FROM {$this->table()}");
    }

    /**
     * @param array<string, mixed> $conditions column => value (ANDed together)
     * @return list<array<string, mixed>>
     */
    public function where(array $conditions): array
    {
        if ($conditions === []) {
            return $this->all();
        }

        $clauses = [];
        foreach (array_keys($conditions) as $column) {
            $clauses[] = "{$column} = ?";
        }

        return $this->db->select(
            "SELECT * FROM {$this->table()} WHERE " . implode(' AND ', $clauses),
            array_values($conditions),
        );
    }

    /**
     * Insert a row and return the generated id.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): string
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        return $this->db->insert(
            "INSERT INTO {$this->table()} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')',
            array_values($data),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return int affected rows
     */
    public function update(int|string $id, array $data): int
    {
        $assignments = [];
        foreach (array_keys($data) as $column) {
            $assignments[] = "{$column} = ?";
        }

        return $this->db->execute(
            "UPDATE {$this->table()} SET " . implode(', ', $assignments) . " WHERE {$this->primaryKey} = ?",
            [...array_values($data), $id],
        );
    }

    /** @return int affected rows */
    public function delete(int|string $id): int
    {
        return $this->db->execute(
            "DELETE FROM {$this->table()} WHERE {$this->primaryKey} = ?",
            [$id],
        );
    }
}
