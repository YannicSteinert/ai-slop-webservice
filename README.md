# Webservice

A small, attribute-routed PHP web service framework. Each module is a
self-contained API (a webservice class); routing, sessions, authentication and
policies are wired once and stay out of your way.

## Request lifecycle

```
public/index.php → Application (composition root)
   └─ Kernel::handle(Request)
        ├─ start session
        ├─ resolve identity             token guard, then session guard
        ├─ Router::match()              resolve module → match attribute route
        ├─ enforce #[Authorize] policies via the Gate
        └─ invoke handler               bind args from path + container → Response
```

These steps live directly in the Kernel (no middleware layer) to keep the
framework small; new cross-cutting behaviour is added by editing that flow.

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

### Inward OAuth2

Send `Authorization: Bearer <token>`. `TokenGuard` maps tokens from `config` →
`oauth2.tokens` to client identities. To use real OAuth2 (introspection or JWT)
in production, replace `TokenGuard` — it is the single seam for inward auth.

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

Logging is PSR-3 (`Psr\Log\LoggerInterface`). The default `LineLogger` writes
one line per record to a file or stream; bind any PSR-3 logger (Monolog, …) on
`LoggerInterface` to replace it. Config (`config/config.php` → `logging`):

```php
'logging' => [
    'path'  => dirname(__DIR__) . '/storage/logs/app.log', // or 'php://stderr'
    'level' => 'debug',
],
```

Every request gets a correlation `request_id` that appears on all of its log
records. Logging is wired at the points that matter:

| Where | Level | What |
|---|---|---|
| Kernel | debug / info | request received; request handled (status + duration) |
| Kernel | error | unhandled exception (message, class, file, trace) |
| Kernel | warning / info | handled `HttpException` (401/403 warn, other 4xx info, 5xx error) |
| LoginWebservice | info / warning | login succeeded / failed; logout |
| Kernel | warning | policy denial (policy, path) |

Example:

```
[2026-10-14T06:47:11+00:00] INFO: Login succeeded via ldap {"request_id":"4e48…","provider":"ldap","subject":"devuser","type":"user"}
[2026-10-14T06:47:11+00:00] WARNING: Request failed with 401: Invalid credentials. {"request_id":"c93c…","status":401,…}
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
$client->withToken('dev-token-onboarding')->get('/users');  // inward path
```

Run the suite:

```bash
php tests/run.php
```

Tests live in `tests/*_test.php` and register cases with `test('name', fn)`.

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
| `src/Auth/` | `Identity`, `Session`, guards, `AuthManager`, LDAP/SAML providers |
| `src/Logging/` | `LineLogger` (PSR-3) |
| `src/Policy/` | `PolicyInterface`, `Gate`, `AuthenticatedPolicy` |
| `src/Modules/` | the modules (`Users`, `Login`, …) |
| `src/Testing/` | `TestClient`, `TestResponse` |
| `tests/` | test suite + runner |
