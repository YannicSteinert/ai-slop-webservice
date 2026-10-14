<?php

declare(strict_types=1);

namespace bapersbw\Database;

use Closure;
use PDO;
use Throwable;

/**
 * Thin PDO wrapper. Connects lazily and exposes a small query surface used by
 * repositories and migrations.
 *
 * The target database is MSSQL (driver `sqlsrv`); `sqlite` is supported for
 * local development and tests. Pass an explicit `dsn` for anything else, or
 * inject a ready PDO (handy in tests).
 *
 * @see config/config.php → database
 */
final class Connection
{
    private ?PDO $pdo;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config = [],
        ?PDO $pdo = null,
    ) {
        $this->pdo = $pdo;
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? ($this->pdo?->getAttribute(PDO::ATTR_DRIVER_NAME) ?? 'sqlsrv'));
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll();
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        return $this->select($sql, $params)[0] ?? null;
    }

    /**
     * Run a write statement; returns the number of affected rows.
     *
     * @param array<string|int, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    /** Insert a row and return the generated id. */
    public function insert(string $sql, array $params = []): string
    {
        $this->execute($sql, $params);

        return $this->pdo()->lastInsertId();
    }

    /**
     * Run a callback inside a transaction, committing on success and rolling
     * back on any exception.
     *
     * @template T
     * @param Closure(self): T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function connect(): PDO
    {
        $driver = $this->driver();
        $username = $this->config['username'] ?? null;
        $password = $this->config['password'] ?? null;

        $options = ($this->config['options'] ?? []) + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if ($driver === 'sqlite') {
            // No credentials for sqlite.
            return new PDO($this->dsn(), null, null, $options);
        }

        return new PDO($this->dsn(), $username, $password, $options);
    }

    private function dsn(): string
    {
        if (isset($this->config['dsn'])) {
            return (string) $this->config['dsn'];
        }

        return match ($this->driver()) {
            'sqlsrv' => sprintf(
                'sqlsrv:Server=%s,%d;Database=%s%s',
                $this->config['host'] ?? 'localhost',
                (int) ($this->config['port'] ?? 1433),
                $this->config['database'] ?? '',
                ($this->config['encrypt'] ?? true) ? ';Encrypt=1;TrustServerCertificate=1' : '',
            ),
            'sqlite' => 'sqlite:' . ($this->config['database'] ?? ':memory:'),
            default => throw new \RuntimeException("Provide a 'dsn' for driver [{$this->driver()}]."),
        };
    }
}
