# Webservice

A small, attribute-routed PHP web service framework. Each module is a
self-contained API (a webservice class); routing, sessions, authentication and
policies are wired once and stay out of your way.

> **New here? Read [`docs/`](docs/README.md).** It covers the architecture and
> walks through [creating a webservice](docs/creating-a-webservice.md) step by
> step. This README is the quick reference.

## Request lifecycle

```
public/index.php → Application (composition root)
   └─ Kernel::handle(Request)
        ├─ start session
        ├─ answer CORS preflight (OPTIONS) early
        ├─ resolve identity             token guard, then session guard
        ├─ Router::match()              resolve module → match attribute route
        ├─ enforce #[Authorize] policies via the Gate
        ├─ invoke handler               bind args from path + container → Response
        └─ apply CORS headers to the response
```

These steps live directly in the Kernel (no middleware layer) to keep the
framework small; new cross-cutting behaviour is added by editing that flow.
CORS is config-driven (`config` → `cors`); see
[docs/architecture.md](docs/architecture.md#cors).

The URL is read as `/{module}/{...rest}`:

- the **first segment** selects the module via `config/modules.php`
  (`'users' => UsersWebservice::class`);
- the **remainder** is matched against that class's `#[Route]` attributes.

## Routing

Routes are attributes on webservice methods, relative to the module. Parameters
use `{name}` and may carry a regex constraint `{name:\d+}`; multiple parameters
per route are supported.

```php
#[Route('GET', '/{group_id}/members/{user_id}')]
public function member(int $group_id, int $user_id): Response { /* … */ }
```

Arguments bind **by name** from the path (cast to the declared scalar type),
plus `Request` by type hint and any other class from the container. A handler
may return a `Response`, `null` (→ 204), or any value (→ JSON).

## Adding a module (e.g. an onboarding API)

Each webservice is a complete API in its own right, so a future onboarding app
is just another module:

1. `src/Modules/Onboarding/OnboardingWebservice.php` extending
   `bapersbw\Webservice\Webservice`, with `#[Route]` methods.
2. `'onboarding' => OnboardingWebservice::class` in `config/modules.php`.

It can be developed and tested in isolation (`TestClient::create(modules: ['onboarding' => …])`).

## Authentication

Two surfaces, selected per request by the guards:

| Surface | Audience | Mechanism | Guard |
|---|---|---|---|
| **Outward** | humans | SAML (prod) / LDAP (dev & fallback), session cookie | `SessionGuard` |
| **Inward** | other services (e.g. the onboarding app) | OAuth2 bearer token | `TokenGuard` |

The Kernel *resolves* the identity (inward token first, then the session) and
attaches it to the request; it never rejects on its own. Rejection is a
**policy** decision, keeping resolution and enforcement separate.

### Outward login (`/login`)

Provider selection is config-driven (`config/config.php` → `auth`):

```php
'auth' => [
    'default' => 'ldap',            // provider for GET /login — 'saml' in prod
    'enabled' => ['ldap', 'saml'],
    'ldap'    => ['fake' => true, 'users' => [...]],   // dev/fallback, no server
    'saml'    => ['settings' => [/* onelogin/php-saml settings */]],
],
```

Routes:

```
GET  /login                      begin login with the default provider
GET  /login/{provider}           begin login with a named provider
GET|POST /login/{provider}/callback   complete login (SAML ACS / LDAP form) → session
GET  /login/logout               clear the session
GET  /login/whoami               current identity (401 if none)
```

- **LDAP** is credential-based. In dev (`fake => true`) it authenticates against
  users in config — no LDAP server or `ext-ldap` needed. In production
  (`fake => false`) `ExtLdapConnector` binds against a real directory.
- **SAML** is redirect-based via `onelogin/php-saml`. `initiate()` redirects to
  the IdP; `/login/saml/callback` is the ACS endpoint. Fill in the `saml.settings`
  (SP/IdP entity ids, ACS URL, IdP cert) to enable it.

### Inward OAuth2 (DB-backed)

The service is an OAuth2 authorization + resource server. `GET /oauth/authorize`
runs the **authorization-code** flow (logs the user in via SAML/LDAP, then
redirects back with a `code`); `POST /oauth/token` exchanges it — with **PKCE**
and client auth via `Authorization: Basic` — for a short-lived access token plus
a rotating **refresh token**. It also supports `client_credentials` (service as
itself) and `refresh_token`. Tokens/codes are stored hashed; `TokenGuard`
validates bearer tokens and rejects expired ones (trigger + `oauth:purge` clean
up). Register clients with `php bin/console oauth:client:create "<name>" <redirect_uri>`.
See [docs/authentication.md](docs/authentication.md#inward-oauth2-db-backed).

## Policies (authorization)

Declarative and off by default:

1. Implement `bapersbw\Policy\PolicyInterface` (or use the built-in `authenticated`).
2. Register it on the gate: `$app->gate()->register('users.view', new ViewUsersPolicy());`
3. Annotate routes/classes:

```php
#[Authorize('authenticated')]            // any signed-in identity
#[Authorize('authenticated', 'client')]  // inward service only
#[Authorize('authenticated', 'user', 'admin')]  // human with the admin role
#[Authorize('users.view')]               // a custom policy
```

The Kernel enforces them via the `Gate` (403 on denial). Routes without
`#[Authorize]` are unaffected.

## Logging

Logging is PSR-3 (`Psr\Log\LoggerInterface`), backed by **Monolog** (built in
[`LoggerFactory`](src/Logging/LoggerFactory.php)), split into three **daily-
rotated** channels kept for `max_files` days — `application-*.log` (request
lifecycle), `security-*.log` (logins, auth failures, policy denials, 401/403)
and `error-*.log` (every error, consolidated). Config (`config/config.php` →
`logging`):

```php
'logging' => [
    'dir'       => dirname(__DIR__) . '/storage/logs',
    'level'     => 'debug',
    'max_files' => 14,   // days kept per channel
],
```

Every request gets a correlation `request_id` on all of its records. Inject
`LoggerInterface` for application logging, or `SecurityLogger` for security
events (it routes to the security channel). See [docs/logging.md](docs/logging.md).

Example (`security-*.log`):

```
[2026-10-16T06:47:11+00:00] security.INFO: Login succeeded via ldap {"request_id":"4e48…","provider":"ldap","subject":"devuser","type":"user"}
[2026-10-16T06:47:11+00:00] security.WARNING: Request failed with 401: Invalid credentials. {"request_id":"c93c…","status":401,…}
```

Tests run with a `NullLogger` by default; pass a capturing logger to
`TestClient::create(logger: …)` to assert on log output.

## Testing

A dependency-free client boots the app with an in-memory session:

```php
use bapersbw\Testing\TestClient;

$client = TestClient::create();
$client->get('/users/5/show')->assertOk()->assertJsonContains(['id' => 5]);

$client->post('/login/ldap/callback', ['username' => 'devuser', 'password' => 'secret'])
       ->assertOk();
$client->get('/login/whoami')->assertOk();            // session carried over

$client->actingAs(new Identity('alice', roles: ['admin']))->get('/protected');
$client->withToken($accessToken)->get('/users');  // inward path (token from /oauth/token)
```

Run the suite:

```bash
php tests/run.php
```

Tests live in `tests/*_test.php` and register cases with `test('name', fn)`.
The database tests run against an in-memory sqlite database when it is
available; enable it locally with `php -d extension=pdo_sqlite tests/run.php`.

## Database

`Database\Connection` is a thin PDO wrapper. The target is **MSSQL** (driver
`sqlsrv`, needs `ext-pdo_sqlsrv`); use `sqlite` for local development/tests, or
an explicit `dsn`. Config lives in `config/config.php` → `database`.

Data access goes through a `Database\Repository` base class — one table per
repository, simple CRUD returning arrays (portable across MSSQL and sqlite). A
repository only needs the `Connection`, so it autowires into a module's
webservice:

```php
final class UsersRepository extends Repository
{
    protected function table(): string { return 'users'; }
}

// in a webservice:
public function __construct(private UsersRepository $users) {}
$this->users->find(5);
$this->users->create(['username' => 'ada', 'email' => 'ada@example.test']);
```

The connection is lazy — nothing connects until a repository runs a query.

## Migrations (module-owned)

Each module owns its schema the same way it owns its routes. Put migration
classes in the module's `Migrations/` folder:

```
src/Modules/Users/
    UsersWebservice.php
    UsersRepository.php
    Migrations/
        M0001_CreateUsersTable.php   implements Database\Migration (up/down)
```

They are discovered by convention from the module map, applied in module order
then class-name order (prefix with `M0001_`, `M0002_`, …), and tracked in a
`migrations` table so each run is one batch:

```bash
php bin/console migrate            # apply pending migrations
php bin/console migrate:rollback   # roll back the most recent batch
php bin/console migrate:status     # list migrations and whether they ran
```

Adding a module brings its own migrations automatically — no central registry.

## Running

```bash
composer install
php -S 127.0.0.1:8080 -t public
```

Behind Apache/nginx, route non-file requests to `public/index.php`
(`public/.htaccess` is included):

```nginx
location / { try_files $uri /index.php$is_args$args; }
```

## Layout

| Path | Responsibility |
|---|---|
| `src/Application.php` | composition root (wires everything from config) |
| `src/Kernel.php` | request orchestration (session, auth, routing, policies, dispatch) |
| `src/Webservice.php` | abstract base class every module extends |
| `src/Attributes/` | `Route`, `Authorize` |
| `src/Http/` | `Request`, `Response` |
| `src/Routing/` | `Router` (module map + attribute loader + matcher), `RouteMatch` |
| `src/Container/` | autowiring DI container |
| `src/Database/` | `Connection`, `Repository`, `Migration`, `Migrator` |
| `src/Auth/` | `Identity`, `Session`, guards, `AuthManager`, LDAP/SAML providers |
| `src/Logging/` | `LoggerFactory` (PSR-3 / Monolog) |
| `src/Policy/` | `PolicyInterface`, `Gate`, `AuthenticatedPolicy` |
| `src/Modules/` | the modules — each with its webservice, repositories, `Migrations/` |
| `src/Testing/` | `TestClient`, `TestResponse` |
| `bin/console` | module-based migration runner |
| `tests/` | test suite + runner |
