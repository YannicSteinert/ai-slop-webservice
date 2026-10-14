# Webservice

A small, attribute-routed PHP web service framework. Each module is a
self-contained API (a webservice class); routing, sessions, authentication and
policies are wired once and stay out of your way.

## Request lifecycle

```
public/index.php → Application (composition root)
   └─ Kernel::handle(Request)
        ├─ Router::match()              resolve module → match attribute route
        ├─ Pipeline (middleware)
        │     ├─ SessionMiddleware        start / persist session
        │     ├─ AuthenticationMiddleware resolve identity (token, then session)
        │     └─ AuthorizationMiddleware  enforce #[Authorize] policies
        └─ handler invocation           bind args from path + container → Response
```

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

`AuthenticationMiddleware` *resolves* the identity (inward token first, then the
session) and attaches it to the request; it never rejects. Rejection is a
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

Send `Authorization: Bearer <token>`. The dev `StaticTokenValidator` maps tokens
from `config` → `oauth2.tokens` to client identities. Swap in OAuth2
introspection or JWT verification by binding a different `TokenValidatorInterface`
— nothing else changes.

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

`AuthorizationMiddleware` enforces them (403 on denial). Routes without
`#[Authorize]` are unaffected.

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
| `src/Kernel.php` | request orchestration |
| `src/Attributes/` | `Route`, `Authorize` |
| `src/Http/` | `Request`, `Response`, `JsonResponse`, `RedirectResponse`, `HttpMethod` |
| `src/Routing/` | `Router`, `AttributeRouteLoader`, `RouteDefinition`, `ArgumentResolver` |
| `src/Module/` | `ModuleRegistry` |
| `src/Container/` | autowiring DI container |
| `src/Middleware/` | `MiddlewareInterface`, `Pipeline` |
| `src/Auth/` | identity, sessions, guards, SAML/LDAP providers, OAuth2 |
| `src/Policy/` | `PolicyInterface`, `Gate`, `AuthorizationMiddleware`, built-in policies |
| `src/Webservice/` | abstract `Webservice` base class |
| `src/Modules/` | the modules (`Users`, `Login`, …) |
| `src/Testing/` | `TestClient`, `TestResponse` |
| `tests/` | test suite + runner |
