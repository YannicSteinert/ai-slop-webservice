# Getting Started

## Prerequisites

- **PHP 8.3+** (uses readonly properties, enums, `match`, first-class attributes).
- **Composer** for autoloading and dependencies.
- For the real database: **`ext-pdo_sqlsrv`** (MSSQL). For local development and
  tests you can use **`ext-pdo_sqlite`** instead (see below).

## Install

```bash
composer install
```

This generates the autoloader (PSR-4: `bapersbw\` → `src/`) and installs the two
runtime dependencies: `onelogin/php-saml` (SAML login), `monolog/monolog` +
`psr/log` (logging).

## Run

```bash
php -S 127.0.0.1:8080 -t public
```

`public/index.php` is the single entry point (front controller). Behind
Apache/nginx, route all non-file requests to it — a `public/.htaccess` is
included; the nginx equivalent is `try_files $uri /index.php$is_args$args;`.

Try it:

```bash
curl http://127.0.0.1:8080/users
curl http://127.0.0.1:8080/users/5/show
```

## Test

```bash
php tests/run.php
```

The database tests execute real SQL against an in-memory sqlite database when it
is available; enable it just for the test run:

```bash
php -d extension=pdo_sqlite tests/run.php
```

See [Testing](testing.md) for how the runner and `TestClient` work.

## Migrations

```bash
php bin/console migrate:status     # list migrations and whether they ran
php bin/console migrate            # apply pending migrations
php bin/console migrate:rollback   # roll back the most recent batch
```

See [Migrations](migrations.md).

## Project layout

```
public/            front controller (index.php) + .htaccess
config/
    config.php     app config: debug, logging, session, auth, oauth2, database
    modules.php    the module map: "users" => UsersWebservice::class
bin/console        migration CLI
src/
    Application.php   composition root (wires everything)
    Kernel.php        request orchestration
    Webservice.php    base class every module extends
    Attributes/       #[Route], #[Authorize]
    Http/             Request, Response
    Routing/          Router, RouteMatch
    Container/         tiny autowiring DI container
    Database/         Connection, Repository, Migration, Migrator
    Auth/             Identity, Session, guards, AuthManager, LDAP/SAML providers
    Policy/           PolicyInterface, Gate, AuthenticatedPolicy
    Logging/          LoggerFactory (PSR-3 / Monolog)
    Modules/          the modules themselves (Users, Login, …)
    Testing/          TestClient, TestResponse
tests/             the test suite + runner
storage/logs/      default log destination
```

## Where things are configured

Everything tunable lives in [`config/config.php`](../config/config.php):

| Key | Purpose | Docs |
|---|---|---|
| `debug` | include exception detail in 500 responses | [Architecture](architecture.md) |
| `logging` | log path + level | [Logging](logging.md) |
| `session` | cookie name/lifetime/flags | [Authentication](authentication.md) |
| `auth` | default/enabled login providers, LDAP + SAML settings | [Authentication](authentication.md) |
| `oauth2` | inward bearer tokens | [Authentication](authentication.md) |
| `database` | MSSQL / sqlite connection | [Database](database.md) |

The module map is separate, in [`config/modules.php`](../config/modules.php).
