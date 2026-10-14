# Database & Repositories

Data access has two pieces: a thin connection wrapper and a repository base
class. There is no ORM — repositories return plain arrays.

## `Connection`

[`Connection`](../src/Database/Connection.php) wraps a PDO handle. It connects
**lazily** (nothing happens until the first query) and exposes a small surface:

| Method | Purpose |
|---|---|
| `select($sql, $params = [])` | all rows (`list<array>`) |
| `selectOne($sql, $params = [])` | first row or `null` |
| `execute($sql, $params = [])` | a write; returns affected row count |
| `insert($sql, $params = [])` | a write; returns the generated id |
| `transaction($callback)` | run a closure in a transaction (commit/rollback) |
| `pdo()` | the underlying `PDO` for anything advanced |
| `driver()` | the driver name (`sqlsrv`, `sqlite`, …) |

All queries use prepared statements — **always pass values as `$params`**, never
interpolate them into SQL.

```php
$rows = $db->select('SELECT * FROM products WHERE qty > ?', [10]);
$one  = $db->selectOne('SELECT * FROM products WHERE id = ?', [$id]);
$db->execute('UPDATE products SET qty = ? WHERE id = ?', [5, $id]);

$db->transaction(function (Connection $db) use ($id) {
    $db->execute('UPDATE accounts SET balance = balance - 1 WHERE id = ?', [$id]);
    $db->execute('INSERT INTO ledger (account_id, delta) VALUES (?, ?)', [$id, -1]);
});
```

### Configuration

[`config/config.php`](../config/config.php) → `database`. The production target
is **MSSQL** (`sqlsrv`, needs `ext-pdo_sqlsrv`):

```php
'database' => [
    'driver'   => 'sqlsrv',
    'host'     => 'localhost',
    'port'     => 1433,
    'database' => 'app',
    'username' => 'sa',
    'password' => '',
    'encrypt'  => true,   // adds Encrypt + TrustServerCertificate for sqlsrv
],
```

For local development/tests, use sqlite:

```php
'database' => ['driver' => 'sqlite', 'database' => ':memory:'],  // or a file path
```

Anything else: provide an explicit `'dsn' => '...'`. You can also construct a
`Connection` from an existing `PDO` (handy in tests).

## `Repository`

[`Repository`](../src/Database/Repository.php) is the base for module data
access. Declare the table (and the primary key, if it isn't `id`):

```php
<?php

namespace bapersbw\Modules\Products;

use bapersbw\Database\Repository;

final class ProductsRepository extends Repository
{
    protected function table(): string { return 'products'; }
    // protected string $primaryKey = 'id';   // override if different

    // add your own domain queries using $this->db:
    public function lowStock(int $threshold): array
    {
        return $this->db->select("SELECT * FROM {$this->table()} WHERE qty < ?", [$threshold]);
    }
}
```

Inherited CRUD:

| Method | SQL |
|---|---|
| `find($id)` | `SELECT * … WHERE id = ?` → row or null |
| `all()` | `SELECT * …` |
| `where(['col' => $v, …])` | `SELECT * … WHERE col = ? AND …` |
| `create(['col' => $v, …])` | `INSERT …` → generated id |
| `update($id, ['col' => $v, …])` | `UPDATE … WHERE id = ?` → affected rows |
| `delete($id)` | `DELETE … WHERE id = ?` → affected rows |

The generated SQL is portable across MSSQL and sqlite. For anything beyond
simple CRUD, drop to `$this->db` (the `Connection`) as above.

## How a repository reaches a webservice

A repository's only dependency is the `Connection`, which `Application` binds
into the container. So repositories **autowire** — just declare one in a
webservice constructor:

```php
public function __construct(private readonly ProductsRepository $products) {}
```

No factory, no registration. See
[Creating a Webservice → dependencies](creating-a-webservice.md#how-dependencies-reach-your-webservice).

## Where the connection is wired

In [`Application`](../src/Application.php):

```php
$container->bind(Connection::class, static fn () => new Connection(
    (array) ($config['database'] ?? []),
));
```

`bind` is lazy and memoised — one connection per request, created only if used.

## Schema changes

Tables are created and evolved with [migrations](migrations.md), owned by each
module. Repositories assume the tables already exist.
