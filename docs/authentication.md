# Authentication

There are two authentication surfaces, by design:

| Surface | Who | How | Guard |
|---|---|---|---|
| **Outward** | humans | SAML (production) or LDAP (dev/fallback) → a session cookie | [`SessionGuard`](../src/Auth/SessionGuard.php) |
| **Inward** | other services (e.g. an onboarding app) | OAuth2 bearer token | [`TokenGuard`](../src/Auth/TokenGuard.php) |

**Resolution vs. enforcement are separate.** The Kernel *resolves* an identity
(tries the token guard first, then the session guard) and attaches it to the
request as the `identity` attribute. It never rejects on its own — requiring
auth is a [policy](authorization.md) decision. So a route is public unless a
policy says otherwise.

## The Identity

Both surfaces produce an [`Identity`](../src/Auth/Identity.php) — a plain value
object:

```php
new Identity(
    id: 'devuser',
    type: Identity::TYPE_USER,     // or TYPE_CLIENT for inward services
    displayName: 'Dev User',
    roles: ['admin'],
    attributes: ['email' => 'dev@example.test'],
    provider: 'ldap',
);
```

Helpers: `hasRole($role)`, `attribute($name)` (first value of a multi-valued
attribute), `toArray()`/`fromArray()` (used to store it in the session).

In a handler or policy, read the current identity from the request:

```php
$identity = $this->request->attribute('identity');   // ?Identity
```

## Outward login (the Login module)

[`LoginWebservice`](../src/Modules/Login/LoginWebservice.php) is mounted at
`/login`. It stays thin — provider selection lives in
[`AuthManager`](../src/Auth/AuthManager.php), session handling in `SessionGuard`,
and the SAML/LDAP mechanics in the providers.

| Route | Purpose |
|---|---|
| `GET /login` | begin login with the **default** provider |
| `GET /login/{provider}` | begin login with a named provider (`ldap`, `saml`) |
| `GET\|POST /login/{provider}/callback` | complete login → establish the session |
| `GET /login/logout` | clear the session |
| `GET /login/whoami` | the current identity (401 if none) |

On success the callback calls `SessionGuard::login($identity)`, which rotates the
session id and stores the identity. It returns the identity as JSON (API-first);
a browser-facing deployment can switch this to a redirect.

### Configuration

[`config/config.php`](../config/config.php) → `auth`:

```php
'auth' => [
    'default' => 'ldap',            // provider for GET /login — set 'saml' in prod
    'enabled' => ['ldap', 'saml'],  // providers that may be used at all

    'ldap' => [ /* see below */ ],
    'saml' => [ /* see below */ ],
],
```

`default` is what makes **LDAP the dev/fallback login and SAML the production
default** — it's one config value, no code change.

### LDAP ([`LdapLoginProvider`](../src/Auth/LdapLoginProvider.php))

Credential-based. Two modes, chosen by config:

```php
'ldap' => [
    // DEV / FALLBACK: authenticate against these users, no LDAP server needed.
    'fake'  => true,
    'users' => [
        'devuser' => [
            'password'     => 'secret',           // plain, or a password_hash() string
            'display_name' => 'Dev User',
            'roles'        => ['admin'],
            'attributes'   => ['email' => 'dev@example.test'],
        ],
    ],

    // REAL LDAP (when 'fake' => false), via ext-ldap:
    'host'      => 'ldap://ldap.example.com',
    'port'      => 389,
    'dn_format' => 'uid=%s,ou=people,dc=example,dc=com',
],
```

`initiate()` renders a tiny HTML login form that POSTs to the callback;
`complete()` reads `username`/`password` and verifies them. API clients can POST
credentials straight to `/login/ldap/callback` and skip the form.

```bash
curl -c jar -X POST -d 'username=devuser&password=secret' \
     http://localhost:8080/login/ldap/callback
curl -b jar http://localhost:8080/login/whoami
```

### SAML ([`SamlLoginProvider`](../src/Auth/SamlLoginProvider.php))

Redirect-based, backed by `onelogin/php-saml`. `initiate()` redirects to the IdP
with an AuthnRequest; `/login/saml/callback` is the ACS endpoint that processes
the SAMLResponse. The onelogin settings pass straight through from config:

```php
'saml' => [
    'attribute_map' => [                 // optional; map SAML attrs → identity
        'display_name' => ['displayName', 'cn'],
        'roles'        => ['memberOf', 'groups'],
    ],
    'settings' => [                      // onelogin/php-saml settings, verbatim
        'strict' => true,
        'sp'  => [ 'entityId' => '...', 'assertionConsumerService' => ['url' => '.../login/saml/callback'] ],
        'idp' => [ 'entityId' => '...', 'singleSignOnService' => ['url' => '...'], 'x509cert' => '...' ],
    ],
],
```

Until the IdP settings are filled in, hitting the SAML flow returns a clear
"SAML is not correctly configured" error.

## Inward OAuth2 (client-credentials, DB-backed)

Service-to-service auth uses the OAuth2 **client-credentials** grant with real,
database-stored, **short-lived** tokens — not a static list. There are no scopes
or roles; a valid token simply identifies the calling service.

Pieces:

- [`Modules\OAuth\OAuthWebservice`](../src/Modules/OAuth/OAuthWebservice.php) —
  the token endpoint, `POST /oauth/token`.
- [`Auth\TokenStore`](../src/Auth/TokenStore.php) — issues/validates/purges
  tokens against the `oauth_tokens` table (only a SHA-256 **hash** of each token
  is stored; `expires_at` is a unix timestamp).
- [`Auth\TokenGuard`](../src/Auth/TokenGuard.php) — the resource server: resolves
  a bearer token to a client identity by validating it against the store.
- [`Modules\OAuth\Migrations\M0001_CreateOAuthTokensTable`](../src/Modules/OAuth/Migrations/M0001_CreateOAuthTokensTable.php)
  — the schema + a self-purging trigger.

### Configuration

Only the token lifetime lives in config — keep it short:

```php
'oauth2' => [
    'ttl' => 900, // token lifetime in seconds
],
```

Run migrations once so the `oauth_tokens` and `oauth_clients` tables exist:
`php bin/console migrate`.

### Registering a client

Clients live in the `oauth_clients` table, created from the console (admin
action — the secret is shown once and stored only as a hash):

```bash
php bin/console oauth:client:create "Onboarding App"
# Client created. Store the secret now — it is not shown again.
#   client_id:     7f3c…           (32 hex chars)
#   client_secret: a19e…           (64 hex chars)
```

### Getting and using a token

```bash
# 1. exchange client credentials for a short-lived token
curl -X POST http://localhost:8080/oauth/token \
     -d 'grant_type=client_credentials&client_id=<id>&client_secret=<secret>'
# → {"access_token":"<hex>","token_type":"Bearer","expires_in":900}

# 2. call protected endpoints with it
curl -H 'Authorization: Bearer <hex>' http://localhost:8080/users
```

Error responses follow OAuth conventions: `{"error":"invalid_client"}` (401) for
bad credentials, `{"error":"unsupported_grant_type"}` (400) otherwise.

### Short-lived tokens & cleanup

- **Expiry is enforced in `TokenGuard`/`TokenStore`** — a token past `expires_at`
  never authenticates, independent of any cleanup. Keep `ttl` small.
- **Cleanup** of expired rows is handled two ways: on MSSQL the migration adds an
  `AFTER INSERT` **trigger** that deletes expired tokens whenever a new one is
  issued; anywhere else (or on a schedule) run `php bin/console oauth:purge`.

### Swapping in another OAuth provider

`TokenGuard` only calls `$this->tokens->validate($raw)`. To use external token
introspection or JWT verification instead, replace `TokenStore` (or `TokenGuard`)
with one that returns an `?Identity` — nothing else changes.

## Sessions

[`Session`](../src/Auth/Session.php) wraps PHP's native session in production and
is an in-memory array in tests (the `TestClient` uses the in-memory mode). Cookie
settings come from `config` → `session` (name, lifetime, `secure`, `http_only`,
`same_site`). The Kernel starts the session at the beginning of each request and
saves it at the end; you rarely touch it directly — go through `SessionGuard`.

The session cookie keeps a human logged in for the session's lifetime; tune its
duration via `config` → `session` (`lifetime`). There is no separate "remember
me" layer — SAML/LDAP logins live in the session.

## Adding authentication requirements to routes

See [Authorization & Policies](authorization.md). In short:

```php
#[Authorize('authenticated')]                    // any signed-in identity
#[Authorize('authenticated', 'user', 'admin')]   // human with the admin role
#[Authorize('authenticated', 'client')]          // an inward service only
```
