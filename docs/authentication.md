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

## Inward OAuth2 ([`TokenGuard`](../src/Auth/TokenGuard.php))

Send `Authorization: Bearer <token>`. The guard maps the token to a **client**
identity (`type = client`). For development, tokens come from config:

```php
'oauth2' => [
    'tokens' => [
        'dev-token-onboarding' => [
            'client_id' => 'onboarding-app',
            'name'      => 'Onboarding App',
            'scopes'    => ['users.read'],   // exposed as the identity's roles
        ],
    ],
],
```

```bash
curl -H 'Authorization: Bearer dev-token-onboarding' http://localhost:8080/users
```

To use real OAuth2 (token introspection or JWT verification) in production,
**replace `TokenGuard`** — it is the single seam for inward auth. Because the
Kernel just calls `$tokenGuard->resolve($request)`, you only need a class with
that method returning an `?Identity`.

## Sessions

[`Session`](../src/Auth/Session.php) wraps PHP's native session in production and
is an in-memory array in tests (the `TestClient` uses the in-memory mode). Cookie
settings come from `config` → `session` (name, lifetime, `secure`, `http_only`,
`same_site`). The Kernel starts the session at the beginning of each request and
saves it at the end; you rarely touch it directly — go through `SessionGuard`.

## Adding authentication requirements to routes

See [Authorization & Policies](authorization.md). In short:

```php
#[Authorize('authenticated')]                    // any signed-in identity
#[Authorize('authenticated', 'user', 'admin')]   // human with the admin role
#[Authorize('authenticated', 'client')]          // an inward service only
```
