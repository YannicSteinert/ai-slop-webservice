<?php

declare(strict_types=1);

/**
 * Application configuration.
 *
 * This file is the committed baseline — edit it with your *production* values
 * (real MSSQL, SAML, real LDAP). For a machine that can't run those (local dev
 * with no MSSQL/LDAP/SSO), drop a `config/config.local.php` next to this file
 * returning only the keys you want to override; it is git-ignored and deep-
 * merged over the values below. See `config/config.local.php.example`.
 */
$config = [
    // When true, 500 responses include the exception message and trace.
    'debug' => true,

    /**
     * The URL path the app is mounted under, stripped before routing. Leave ''
     * to auto-detect from the front controller's location (works for most
     * setups). Set it when the server can't be configured, e.g.
     *   '/foo/ws/public'            served under a sub-path, or
     *   '/foo/ws/public/index.php'  when URL rewriting is unavailable
     *                               (access routes as /foo/ws/public/index.php/users/5).
     */
    'base_path' => '',

    /**
     * CORS. Browsers send an OPTIONS preflight before cross-origin POSTs that
     * carry an Authorization header or a JSON body (e.g. POST /oauth/token).
     * The kernel answers that preflight and stamps these headers on responses.
     *
     * `allowed_origins` is an explicit allow-list of scheme://host[:port]
     * values, or ['*'] for any. With `allow_credentials => true` the matched
     * origin is echoed back (the spec forbids '*' together with credentials).
     * Leave `allowed_headers` null to mirror whatever the preflight requests.
     */
    'cors' => [
        'allowed_origins' => ['http://localhost:5173'],
        'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
        'allowed_headers' => null, // null = reflect Access-Control-Request-Headers
        'allow_credentials' => true,
        'max_age' => 600,
    ],

    /**
     * Logging. Each channel writes its own daily-rotated file in `dir`, kept for
     * `max_files` days:
     *   application-YYYY-MM-DD.log   request lifecycle
     *   security-YYYY-MM-DD.log      logins, auth failures, policy denials
     *   error-YYYY-MM-DD.log         every ERROR, from any channel
     * `level` is the minimum PSR-3 level written. Set `path` to a stream (e.g.
     * 'php://stderr') to log to one stream instead of rotating files.
     */
    'logging' => [
        'dir' => dirname(__DIR__) . '/storage/logs',
        'level' => 'debug',
        'max_files' => 14,
    ],

    /**
     * Database. The target is MSSQL (driver 'sqlsrv', needs ext-pdo_sqlsrv).
     * Use driver 'sqlite' with database ':memory:' or a file path for local
     * development, or provide an explicit 'dsn' for anything else.
     */
    'database' => [
        'driver' => 'sqlite',
        'host' => 'localhost',
        'port' => 1433,
        'database' => dirname(__DIR__) . '/storage/dev.sqlite',
        'username' => 'sa',
        'password' => '',
        'encrypt' => true, // adds Encrypt + TrustServerCertificate for sqlsrv
    ],

    /**
     * Session storage (used by NativeSession in production).
     */
    'session' => [
        'name' => 'BAPERSBWSESSID',
        'lifetime' => 0,
        'path' => '/',
        'secure' => false,     // set true behind HTTPS
        'http_only' => true,
        'same_site' => 'Lax',
    ],

    /**
     * Outward (human) authentication.
     *
     * `default` is the provider used by GET /login. LDAP is the development /
     * fallback login; switch `default` to 'saml' in production. `enabled` gates
     * which providers may be used at all.
     */
    'auth' => [
        'default' => 'ldap',
        'enabled' => ['ldap', 'saml'],

        'ldap' => [
            // Development/fallback mode: authenticate against the users below,
            // no LDAP server required. Set 'fake' => false to use ext-ldap.
            'fake' => true,
            'users' => [
                'devuser' => [
                    'password' => 'secret',
                    'display_name' => 'Dev User',
                    'roles' => ['admin'],
                    'attributes' => ['email' => 'dev@example.test'],
                ],
            ],

            // Real LDAP settings (used when 'fake' => false):
            'host' => 'ldap://ldap.example.com',
            'port' => 389,
            'dn_format' => 'uid=%s,ou=people,dc=example,dc=com',
            'base_dn' => 'ou=people,dc=example,dc=com',
            'attributes' => ['cn', 'mail', 'memberOf'],
            'start_tls' => false,
        ],

        'saml' => [
            // Maps SAML attribute names onto the identity. Defaults cover the
            // common cases (displayName/cn, memberOf/groups) when omitted.
            'attribute_map' => [
                'display_name' => ['displayName', 'cn'],
                'roles' => ['memberOf', 'groups'],
            ],

            // Passed verbatim to the onelogin/php-saml toolkit. Fill in with
            // your SP and IdP details before enabling SAML in production.
            'settings' => [
                'strict' => true,
                'debug' => false,
                'sp' => [
                    'entityId' => 'https://service.example.com/login/saml/metadata',
                    'assertionConsumerService' => [
                        'url' => 'https://service.example.com/login/saml/callback',
                    ],
                ],
                'idp' => [
                    'entityId' => '',
                    'singleSignOnService' => ['url' => ''],
                    'x509cert' => '',
                ],
            ],
        ],
    ],

    /**
     * Inward (service-to-service) authentication: OAuth2 client-credentials.
     *
     * A client POSTs its id + secret to /oauth/token and receives a short-lived
     * bearer token (stored, hashed, in the oauth_tokens table). `ttl` is the
     * token lifetime in seconds — keep it short.
     *
     * Clients live in the oauth_clients table. Create one with:
     *   php bin/console oauth:client:create "My Service" https://app/callback
     *
     * Supports grant_type = client_credentials | authorization_code (with PKCE)
     * | refresh_token.
     */
    'oauth2' => [
        'ttl' => 900,            // access token lifetime (seconds)
        'code_ttl' => 300,       // authorization code lifetime (seconds)
        'refresh_ttl' => 1209600, // refresh token lifetime (seconds, 14 days)
    ],
];

/**
 * Deep-merge an optional, git-ignored local overlay over the baseline above.
 * Associative arrays are merged key-by-key (recursively); any other value
 * (scalars and list arrays such as `allowed_origins` or the `users` map)
 * replaces the baseline wholesale. This lets a dev machine override only what
 * it needs (e.g. the database driver) without copying the whole file.
 */
$localConfig = __DIR__ . '/config.local.php';

if (is_file($localConfig)) {
    $deepMerge = static function (array $base, array $over) use (&$deepMerge): array {
        foreach ($over as $key => $value) {
            if (
                is_string($key)
                && isset($base[$key])
                && is_array($base[$key])
                && is_array($value)
                && array_is_list($value) === false
            ) {
                $base[$key] = $deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    };

    $config = $deepMerge($config, (array) require $localConfig);
}

return $config;
