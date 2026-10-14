<?php

declare(strict_types=1);

use bapersbw\Database\Connection;
use bapersbw\Database\Migration;
use bapersbw\Database\Migrator;
use bapersbw\Database\Repository;
use bapersbw\Modules\Users\UsersWebservice;

// Module-based discovery needs no database — verify the convention directly.
test('migrator discovers module-owned migrations by convention', function (): void {
    $discovered = Migrator::discover(['users' => UsersWebservice::class]);

    assert_same(1, count($discovered), 'expected one users migration');
    assert_same('users', $discovered[0]['module']);
    assert_same('M0001_CreateUsersTable', $discovered[0]['name']);
    assert_true($discovered[0]['migration'] instanceof Migration);
});

// The rest exercise real SQL; run them only when sqlite is available
// (php -d extension=pdo_sqlite tests/run.php). MSSQL is the production target.
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    test('database CRUD + migrations (skipped: no pdo_sqlite)', function (): void {
        assert_true(true);
    });

    return;
}

function sqlite_connection(): Connection
{
    return new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
}

function widgets_repo(Connection $db): Repository
{
    return new class ($db) extends Repository {
        protected function table(): string
        {
            return 'widgets';
        }
    };
}

function widgets_migration(): Migration
{
    return new class implements Migration {
        public function up(Connection $db): void
        {
            $db->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, qty INTEGER)');
        }

        public function down(Connection $db): void
        {
            $db->execute('DROP TABLE widgets');
        }
    };
}

test('repository performs CRUD', function (): void {
    $db = sqlite_connection();
    $db->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, qty INTEGER)');
    $repo = widgets_repo($db);

    $id = (int) $repo->create(['name' => 'Gear', 'qty' => 3]);
    assert_true($id > 0, 'create should return a new id');

    $row = $repo->find($id);
    assert_same('Gear', $row['name'] ?? null);

    $repo->update($id, ['qty' => 9]);
    assert_same(9, (int) $repo->find($id)['qty']);

    assert_same(1, count($repo->where(['name' => 'Gear'])));

    $repo->delete($id);
    assert_same(null, $repo->find($id));
});

test('migrator applies, is idempotent, reports status, and rolls back', function (): void {
    $db = sqlite_connection();
    $migrator = new Migrator($db);
    $discovered = [['module' => 'test', 'name' => 'M0001_Widgets', 'migration' => widgets_migration()]];

    $ran = $migrator->migrate($discovered);
    assert_same(1, count($ran), 'first migrate should apply one migration');

    // Table now exists and is usable.
    $db->execute("INSERT INTO widgets (name, qty) VALUES ('x', 1)");
    assert_same(1, count($db->select('SELECT * FROM widgets')));

    // Running again applies nothing.
    assert_same(0, count($migrator->migrate($discovered)));

    $status = $migrator->status($discovered);
    assert_true($status[0]['applied'], 'migration should report as applied');

    // Rolling back drops the table and clears the record.
    $rolled = $migrator->rollback($discovered);
    assert_same(1, count($rolled));
    assert_true(!$migrator->status($discovered)[0]['applied'], 'migration should report as not applied after rollback');
});
