# Testing

Tests are dependency-free: a tiny runner plus an in-process HTTP client. No
PHPUnit, no web server. Because each module is a self-contained API, you can
test it in isolation.

## Running

```bash
php tests/run.php
```

The runner ([`tests/run.php`](../tests/run.php)) loads every `tests/*_test.php`,
executes the registered cases and exits non-zero if any fail (CI-friendly).

The database tests execute real SQL only when sqlite is available — enable it
for the run:

```bash
php -d extension=pdo_sqlite tests/run.php
```

## Writing a test

A test file registers cases with `test('name', fn)` (from
[`tests/support.php`](../tests/support.php)). Assertions throw on failure.

```php
// tests/products_test.php
<?php

test('GET /products returns a list', function (): void {
    client()->get('/products')
        ->assertOk()
        ->assertJsonContains(['products' => []]);
});
```

Helpers available in every test:

| Helper | Use |
|---|---|
| `client($config = null, $modules = null)` | a fresh `TestClient` (defaults to the real config/modules) |
| `assert_true($cond, $msg = '')` | assert a boolean |
| `assert_same($expected, $actual, $msg = '')` | strict equality |
| `assert_throws($class, $fn)` | assert `$fn` throws that exception type |

## The `TestClient`

[`TestClient`](../src/Testing/TestClient.php) boots the whole `Application` with
an **in-memory session** and a `NullLogger`, then lets you drive it:

```php
$client = client();                       // or TestClient::create(...)

$client->get('/users/5/show');
$client->post('/users', ['name' => 'Grace Hopper']);
$client->put('/users/5', ['name' => 'Ada']);
$client->delete('/users/5');
$client->request('PATCH', '/users/5', body: [...], query: [...], headers: [...]);
```

Each call returns a [`TestResponse`](../src/Testing/TestResponse.php).

### Session continuity

One `TestClient` keeps a single in-memory session across requests, so login
flows carry over:

```php
$client = client();
$client->post('/login/ldap/callback', ['username' => 'devuser', 'password' => 'secret'])
       ->assertOk();
$client->get('/login/whoami')->assertOk();   // same session → still signed in
```

### Shortcuts for authenticated requests

```php
use bapersbw\Auth\Identity;

// Seed a session identity without going through the login flow:
$client->actingAs(new Identity('alice', roles: ['admin']))
       ->get('/protected')->assertOk();

// Send an inward OAuth2 bearer token:
$client->withToken('dev-token-onboarding')
       ->get('/users')->assertOk();
```

## Assertions — `TestResponse`

| Method | Checks / returns |
|---|---|
| `assertStatus($code)` | the HTTP status |
| `assertOk()` | status is 200 |
| `assertHeader($name, $value = null)` | a header is present (and equals `$value`) |
| `assertJsonContains([...])` | the JSON body contains these keys/values (recursive subset) |
| `status()` / `body()` / `header($n)` / `json()` | raw accessors |

All assertions return `$this`, so they chain.

## Testing with a custom config

`client()` (and `TestClient::create()`) accept overrides — useful for toggling
auth providers, pointing at sqlite, etc.:

```php
$config = require dirname(__DIR__) . '/config/config.php';
$config['auth']['enabled'] = ['ldap'];          // disable SAML for this test
$config['database'] = ['driver' => 'sqlite', 'database' => ':memory:'];

client($config)->get('/login/saml')->assertStatus(404);
```

You can also boot with a **subset of modules** to test one in isolation:

```php
client(modules: ['products' => ProductsWebservice::class])->get('/products');
```

## Asserting on logs

Pass a capturing PSR-3 logger to `TestClient::create()`:

```php
use Psr\Log\AbstractLogger;

$logger = new class extends AbstractLogger {
    public array $records = [];
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }
};

TestClient::create(logger: $logger)
    ->post('/login/ldap/callback', ['username' => 'devuser', 'password' => 'wrong']);

// then assert $logger->records contains a 'warning' with 'Login failed'
```

See [`tests/logging_test.php`](../tests/logging_test.php) for a complete example.

## Database-backed tests

Build a sqlite `Connection`, run a migration (or raw DDL), then exercise a
repository. See [`tests/database_test.php`](../tests/database_test.php):

```php
$db = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
$repo = new class ($db) extends Repository {
    protected function table(): string { return 'widgets'; }
};
$id = (int) $repo->create(['name' => 'Gear']);
assert_same('Gear', $repo->find($id)['name']);
```

Guard such tests with `in_array('sqlite', PDO::getAvailableDrivers(), true)` so
the suite still passes where sqlite isn't loaded.
