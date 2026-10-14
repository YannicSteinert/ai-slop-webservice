<?php

declare(strict_types=1);

namespace bapersbw\Database;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Runs module-owned migrations against the database.
 *
 * {@see discover()} walks the module map and finds each module's
 * Migrations/ classes by convention; the instance methods apply them, roll the
 * last batch back, or report status. Applied migrations are tracked in a
 * `migrations` table (module + name + batch), so each module's history is
 * independent while sharing one ledger.
 *
 * @phpstan-type Discovered array{module: string, name: string, migration: Migration}
 */
final class Migrator
{
    private readonly LoggerInterface $logger;

    private bool $tableReady = false;

    public function __construct(
        private readonly Connection $db,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Find all module migrations by convention (Modules/<Name>/Migrations/),
     * in module order then class-name order.
     *
     * @param array<string, class-string> $modules name => webservice class
     * @return list<Discovered>
     */
    public static function discover(array $modules): array
    {
        $discovered = [];

        foreach ($modules as $module => $webserviceClass) {
            $reflection = new ReflectionClass($webserviceClass);
            $namespace = $reflection->getNamespaceName() . '\\Migrations';
            $directory = dirname((string) $reflection->getFileName()) . '/Migrations';

            if (!is_dir($directory)) {
                continue;
            }

            $files = glob($directory . '/*.php') ?: [];
            sort($files);

            foreach ($files as $file) {
                $class = $namespace . '\\' . basename($file, '.php');
                if (class_exists($class) && is_subclass_of($class, Migration::class)) {
                    $discovered[] = [
                        'module' => (string) $module,
                        'name' => basename($file, '.php'),
                        'migration' => new $class(),
                    ];
                }
            }
        }

        return $discovered;
    }

    /**
     * Apply all pending migrations in one batch. Returns the names applied.
     *
     * @param list<Discovered> $discovered
     * @return list<string>
     */
    public function migrate(array $discovered): array
    {
        $this->ensureTable();
        $applied = $this->appliedKeys();
        $batch = $this->currentBatch() + 1;
        $ran = [];

        foreach ($discovered as $item) {
            $key = $item['module'] . '|' . $item['name'];
            if (isset($applied[$key])) {
                continue;
            }

            $item['migration']->up($this->db);
            $this->db->execute(
                'INSERT INTO migrations (module, migration, batch) VALUES (?, ?, ?)',
                [$item['module'], $item['name'], $batch],
            );
            $this->logger->info('Migrated {module}/{name}', ['module' => $item['module'], 'name' => $item['name']]);
            $ran[] = $item['module'] . '/' . $item['name'];
        }

        return $ran;
    }

    /**
     * Roll back the most recent batch. Returns the names rolled back.
     *
     * @param list<Discovered> $discovered
     * @return list<string>
     */
    public function rollback(array $discovered): array
    {
        $this->ensureTable();
        $batch = $this->currentBatch();
        if ($batch === 0) {
            return [];
        }

        $index = [];
        foreach ($discovered as $item) {
            $index[$item['module'] . '|' . $item['name']] = $item['migration'];
        }

        $rows = $this->db->select(
            'SELECT module, migration FROM migrations WHERE batch = ?',
            [$batch],
        );
        // Reverse application order within the batch.
        $rows = array_reverse($rows);
        $rolled = [];

        foreach ($rows as $row) {
            $key = $row['module'] . '|' . $row['migration'];
            $index[$key]?->down($this->db);
            $this->db->execute(
                'DELETE FROM migrations WHERE module = ? AND migration = ?',
                [$row['module'], $row['migration']],
            );
            $this->logger->info('Rolled back {module}/{migration}', ['module' => $row['module'], 'migration' => $row['migration']]);
            $rolled[] = $row['module'] . '/' . $row['migration'];
        }

        return $rolled;
    }

    /**
     * @param list<Discovered> $discovered
     * @return list<array{module: string, name: string, applied: bool}>
     */
    public function status(array $discovered): array
    {
        $this->ensureTable();
        $applied = $this->appliedKeys();

        return array_map(
            static fn (array $item): array => [
                'module' => $item['module'],
                'name' => $item['name'],
                'applied' => isset($applied[$item['module'] . '|' . $item['name']]),
            ],
            $discovered,
        );
    }

    private function ensureTable(): void
    {
        if ($this->tableReady) {
            return;
        }

        $this->db->execute($this->driverDdl());
        $this->tableReady = true;
    }

    private function driverDdl(): string
    {
        if ($this->db->driver() === 'sqlsrv') {
            return <<<'SQL'
                IF OBJECT_ID('migrations', 'U') IS NULL
                CREATE TABLE migrations (
                    id INT IDENTITY(1,1) PRIMARY KEY,
                    module NVARCHAR(100) NOT NULL,
                    migration NVARCHAR(200) NOT NULL,
                    batch INT NOT NULL,
                    applied_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
                )
                SQL;
        }

        return <<<'SQL'
            CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                module TEXT NOT NULL,
                migration TEXT NOT NULL,
                batch INTEGER NOT NULL,
                applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL;
    }

    /** @return array<string, true> */
    private function appliedKeys(): array
    {
        $keys = [];
        foreach ($this->db->select('SELECT module, migration FROM migrations') as $row) {
            $keys[$row['module'] . '|' . $row['migration']] = true;
        }

        return $keys;
    }

    private function currentBatch(): int
    {
        $row = $this->db->selectOne('SELECT MAX(batch) AS batch FROM migrations');

        return (int) ($row['batch'] ?? 0);
    }
}
