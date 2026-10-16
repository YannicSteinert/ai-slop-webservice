# Migrations

Migrations are **owned by modules**, the same way routes are. Each module keeps
its schema changes in its own `Migrations/` folder, so a module is a complete,
self-contained slice — add the module and it brings its tables with it. There is
no central migration registry.

```
src/Modules/Users/
    UsersWebservice.php
    UsersRepository.php
    Migrations/
        M0001_CreateUsersTable.php
        M0002_AddEmailIndex.php
```

## Writing a migration

Implement [`Migration`](../src/Database/Migration.php): `up()` applies the
change, `down()` reverses it. Both receive the [`Connection`](database.md).

```php
<?php

declare(strict_types=1);

namespace bapersbw\Modules\Users\Migrations;

use bapersbw\Database\Connection;
use bapersbw\Database\Migration;

final class M0001_CreateUsersTable implements Migration
{
    public function up(Connection $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE users (
                id INT IDENTITY(1,1) PRIMARY KEY,
                username NVARCHAR(100) NOT NULL UNIQUE,
                display_name NVARCHAR(200) NULL,
                email NVARCHAR(256) NULL,
                created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
            )
            SQL);
    }

    public function down(Connection $db): void
    {
        $db->execute("IF OBJECT_ID('users', 'U') IS NOT NULL DROP TABLE users");
    }
}
```

Write the SQL for your target database. The snippet above is pure MSSQL DDL
(`IDENTITY`, `NVARCHAR`, `DATETIME2`). If you also develop locally on **sqlite**
(see [Getting Started](getting-started.md#local-development-without-mssql--ldap--sso)),
make the migration driver-aware so the same class builds both schemas — the
bundled migrations do exactly this:

```php
public function up(Connection $db): void
{
    $db->execute(match ($db->driver()) {
        'sqlite' => 'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE, …)',
        default  => 'CREATE TABLE users (id INT IDENTITY(1,1) PRIMARY KEY, username NVARCHAR(100) NOT NULL UNIQUE, …)',
    });
}

public function down(Connection $db): void
{
    $db->execute(match ($db->driver()) {
        'sqlite' => 'DROP TABLE IF EXISTS users',
        default  => "IF OBJECT_ID('users', 'U') IS NOT NULL DROP TABLE users",
    });
}
```

The `sqlsrv` branch is what runs in production; the `sqlite` branch is only for
local dev. If you only ever target MSSQL, a single statement is fine.

## Naming and ordering

- The **class name** is the migration's identity and its sort key.
- Prefix with a zero-padded, sortable number: `M0001_`, `M0002_`, …
- Migrations run in **module order** (as listed in `config/modules.php`), then
  **class-name order** within each module.
- The file name must match the class name (PSR-4): `M0001_CreateUsersTable.php`.

## Running migrations — `bin/console`

```bash
php bin/console migrate            # apply all pending migrations (one batch)
php bin/console migrate:rollback   # roll back the most recent batch
php bin/console migrate:status     # list every migration and whether it ran
```

The same console also hosts OAuth admin commands —
`oauth:client:create "<name>"` and `oauth:purge` (see
[Authentication](authentication.md)).

`migrate:status` output:

```
[x] users/M0001_CreateUsersTable
[ ] products/M0001_CreateProductsTable
```

(`x` = applied, blank = pending.)

## How it works

[`Migrator`](../src/Database/Migrator.php):

1. **Discovers** migrations: for each module in the map, it finds the class's
   directory, scans `Migrations/*.php`, and instantiates classes that implement
   `Migration` (`Migrator::discover($modules)`).
2. **Tracks** applied migrations in a `migrations` table
   (`module`, `migration`, `batch`, `applied_at`), created automatically with
   driver-appropriate DDL (MSSQL or sqlite).
3. **Applies** pending ones in a single new batch, recording each.
4. **Rolls back** the latest batch by calling `down()` in reverse and deleting
   the records.

Because applied state is keyed by `module` + migration name, each module's
history is independent while sharing one ledger table.

## Tips

- Keep each migration focused and reversible — a good `down()` saves you later.
- Don't edit a migration that has already run in shared environments; add a new
  one instead.
- Seeding data? A migration can `INSERT`, or write a small one-off script using
  the `Connection` directly.
- Test migration mechanics locally against sqlite
  (`php -d extension=pdo_sqlite tests/run.php`); see
  [`tests/database_test.php`](../tests/database_test.php) for the pattern.
