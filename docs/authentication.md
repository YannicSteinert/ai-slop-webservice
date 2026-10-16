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
    'host'       => 'ldap://ldap.example.com', // or ldaps://… for LDAPS
    'port'       => 389,                        // 636 for LDAPS
    'dn_format'  => 'uid=%s,ou=people,dc=example,dc=com', // %s = username
    'start_tls'  => false,                      // true to upgrade a 389 connection to TLS
    'attributes' => ['cn', 'mail', 'memberOf'], // optional: fetched after bind
    'base_dn'    => 'ou=people,dc=example,dc=com', // fallback search base for attributes
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

Real-LDAP config keys:

- **`dn_format`** — the bind DN template (`%s` = username). Must match your
  directory (e.g. AD: `%s@example.com` or `CN=%s,OU=Users,DC=example,DC=com`).
  A wrong template is the most common cause of a failed login.
- **`start_tls`** — `true` upgrades a port-389 connection to TLS before binding;
  alternatively use `ldaps://` + port 636 and leave this false.
- **`attributes`** — fetched after a successful bind; `cn` → display name,
  `memberOf` → roles. `base_dn` is the fallback search base. Omit to skip.

Every failed bind returns the same generic **401 "Invalid credentials."** to the
client, but the *real* reason is logged at `warning`:

- `LDAP (fake mode) auth failed: …` — you're still in `fake => true` mode (it
  never contacted the directory); set `fake => false` to use real LDAP.
- `LDAP bind failed for <dn>: <error> <diagnostic>` — the directory rejected it;
  the error text distinguishes a wrong `dn_format` (`Invalid DN syntax`), bad
  credentials (AD subcode `52e`), unknown user (`525`), or connectivity/TLS.

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

## Inward OAuth2 (DB-backed)

The service is an OAuth2 **authorization server + resource server**, with real,
database-stored, **short-lived** access tokens. Three grants are supported:

| Grant | For | Token represents |
|---|---|---|
| `authorization_code` (+ PKCE, refresh) | an app acting for a logged-in **user** | the user |
| `client_credentials` | a service acting as **itself** | the client |
| `refresh_token` | renewing without re-authorizing | (same as the code's user) |

There are no scopes/roles — a valid token simply identifies the principal.

Pieces:

- [`OAuthWebservice`](../src/Modules/OAuth/OAuthWebservice.php) — `GET /oauth/authorize`
  and `POST /oauth/token`.
- [`TokenStore`](../src/Auth/TokenStore.php) — the server core: clients, codes,
  access + refresh tokens. Everything is stored only as a SHA-256 **hash**;
  `expires_at` is a unix timestamp; `identity` is the principal's JSON.
- [`TokenGuard`](../src/Auth/TokenGuard.php) — the resource server: resolves a
  bearer access token to its identity.
- Migrations under [`Modules/OAuth/Migrations/`](../src/Modules/OAuth/Migrations/)
  own the `oauth_clients`, `oauth_tokens`, `oauth_auth_codes` and
  `oauth_refresh_tokens` tables (the access-token table has a self-purging trigger).

### Configuration & client registration

```php
'oauth2' => [
    'ttl' => 900,             // access token lifetime (seconds)
    'code_ttl' => 300,        // authorization code lifetime
    'refresh_ttl' => 1209600, // refresh token lifetime (14 days)
],
```

Run `php bin/console migrate`, then register a client. Two kinds:

**Confidential** — a server-side client that can keep a secret (shown once,
stored hashed). Can use any grant.

```bash
php bin/console oauth:client:create "Onboarding App" https://app.example/callback
#   client_id:     7f3c…   client_secret: a19e…
```

**Public** — a browser app (SPA: Vue/React/…) that *cannot* keep a secret. It
has no secret at all; **PKCE is mandatory** and it is restricted to the
`authorization_code` + `refresh_token` grants (never `client_credentials`). A
public client is marked internally by an empty `secret_hash`, which can never
match a real one.

```bash
php bin/console oauth:client:create:public "Vite SPA" http://localhost:5173/callback
#   client_id:     416b…   (no secret)
```

For a public client: `/authorize` rejects a request with no `code_challenge`
(`invalid_request`), and `/token` takes only `client_id` + `code_verifier` — no
`Authorization: Basic`, no `client_secret`. Make sure the SPA's dev/prod origin
is in `config` → `cors.allowed_origins`.

### Authorization-code flow (user-delegated)

```
1. GET /oauth/authorize?response_type=code&client_id=…&redirect_uri=…
        &state=…&code_challenge=…&code_challenge_method=S256
   → if no session: 302 to /login?return_to=… (user logs in via SAML/LDAP,
     then is redirected back here)
   → 302 to <redirect_uri>?code=…&state=…

2. POST /oauth/token            (confidential: Authorization: Basic id:secret;
                                 public: client_id in the body, no secret)
     grant_type=authorization_code&code=…&redirect_uri=…&code_verifier=…
   → { "access_token", "token_type":"Bearer", "expires_in", "refresh_token" }
```

- `redirect_uri` must **exactly match** one registered for the client.
- **PKCE**: send `code_challenge` (S256) at authorize and the matching
  `code_verifier` at token; codes are single-use and short-lived.
- The resulting access token identifies the **user** (`type: user`).

```bash
# renew without bouncing the user through /authorize
curl -u <id>:<secret> -X POST http://localhost:8080/oauth/token \
     -d 'grant_type=refresh_token&refresh_token=<refresh>'
```

Refresh tokens are **rotated**: each use returns a new one and invalidates the
old.

### Client-credentials flow (service-to-service)

```bash
curl -u <id>:<secret> -X POST http://localhost:8080/oauth/token \
     -d 'grant_type=client_credentials'
# → { "access_token", "token_type":"Bearer", "expires_in":900 }   (no refresh)
curl -H 'Authorization: Bearer <token>' http://localhost:8080/users
```

The token identifies the **client** (`type: client`).

### Errors, expiry & cleanup

- OAuth-style errors: `invalid_client` (401), `invalid_grant` (400),
  `unsupported_grant_type` (400); `/authorize` redirects recoverable errors back
  to a valid `redirect_uri` with `?error=…&state=…`.
- Expiry is enforced on every validation — a token past `expires_at` never
  authenticates. Cleanup: the access-token table self-purges via an MSSQL
  trigger; run `php bin/console oauth:purge` (cron) to clear expired codes and
  refresh tokens too.

### Swapping in another OAuth provider

`TokenGuard` only calls `$this->tokens->validate($raw)`. To validate tokens with
external introspection or JWT instead, replace `TokenStore`/`TokenGuard` with one
that returns an `?Identity` — nothing else changes.

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
