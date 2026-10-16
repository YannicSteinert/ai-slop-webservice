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

`public/index.php` is the single entry point (front controller). Behind a real
web server, route all non-file requests to it:

- **IIS**: `public/web.config` is included (needs the URL Rewrite module).
- **Apache**: `public/.htaccess` is included.
- **nginx**: `try_files $uri /index.php$is_args$args;`

The app is **mount-point aware** — it strips its own base path (auto-detected
from `SCRIPT_NAME`), so it works at `/` or under a sub-path like
`/foo/ws/public/` with no change. If auto-detect is wrong, set it explicitly via
config `base_path` (or the `BASE_PATH` env var), e.g. `'/foo/ws/public'`.

**No URL Rewrite available?** You don't need it — set
`'base_path' => '/foo/ws/public/index.php'` and route requests through the
script directly: `…/foo/ws/public/index.php/users/5/show`.

Try it:

```bash
curl http://127.0.0.1:8080/users
curl http://127.0.0.1:8080/users/5/show
```

## Local development without MSSQL / LDAP / SSO

You can run and test the whole service on a machine that has **no MSSQL server,
no real LDAP directory and no SAML IdP** — and still clone the same repo onto the
production IIS box unchanged. Two mechanisms make this work:

1. **A git-ignored config overlay.** `config/config.php` is the committed
   baseline you edit with *production* values. Drop a `config/config.local.php`
   next to it returning only the keys you want to override; it is deep-merged
   over the baseline and **git-ignored**, so it never follows the repo to prod.
   Copy the template to get going:

   ```bash
   cp config/config.local.php.example config/config.local.php
   ```

   The example points the database at a local **sqlite** file and keeps LDAP in
   `fake` mode (log in as `devuser` / `secret`). SAML is simply not used locally.

2. **Driver-aware migrations.** Each migration emits sqlite DDL when the
   `database.driver` is `sqlite` and MSSQL T-SQL otherwise — so `bin/console
   migrate` builds the same schema locally that it builds on MSSQL.

sqlite needs `ext-pdo_sqlite`. It ships with most PHP builds but may be off by
default (as on this setup) — enable it per command with `-d extension=pdo_sqlite`
(or in `php.ini` for good). Set up and run locally:

```bash
php -d extension=pdo_sqlite bin/console migrate                       # build the schema
php -d extension=pdo_sqlite bin/console oauth:client:create "Local"    # (optional) an OAuth client
php -d extension=pdo_sqlite -S 127.0.0.1:8080 -t public               # serve
```

Then `devuser` / `secret` logs you in at `/login`, and the OAuth endpoints work
against the sqlite file. On the production clone you leave `config.local.php` out
entirely and edit `config/config.php` instead — see [Deployment](deployment.md).

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
| `base_path` | mount sub-path to strip (`''` = auto) | [Architecture](architecture.md) |
| `cors` | allowed cross-origin origins/methods/headers | [Architecture](architecture.md#cors) |
| `logging` | log path + level | [Logging](logging.md) |
| `session` | cookie name/lifetime/flags | [Authentication](authentication.md) |
| `auth` | default/enabled login providers, LDAP + SAML settings | [Authentication](authentication.md) |
| `oauth2` | inward bearer tokens | [Authentication](authentication.md) |
| `database` | MSSQL / sqlite connection | [Database](database.md) |

The module map is separate, in [`config/modules.php`](../config/modules.php).
