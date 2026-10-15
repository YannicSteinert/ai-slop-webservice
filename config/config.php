<?php

declare(strict_types=1);

/**
 * Application configuration.
 */
return [
    // When true, 500 responses include the exception message and trace.
    'debug' => true,

    /**
     * Logging. `path` is a file path or a PHP stream (e.g. 'php://stderr');
     * `level` is the minimum PSR-3 level that gets written. Bind any PSR-3
     * logger (Monolog, ...) on LoggerInterface to replace the default.
     */
    'logging' => [
        'path' => dirname(__DIR__) . '/storage/logs/app.log',
        'level' => 'debug',
    ],

    /**
     * Database. The target is MSSQL (driver 'sqlsrv', needs ext-pdo_sqlsrv).
     * Use driver 'sqlite' with database ':memory:' or a file path for local
     * development, or provide an explicit 'dsn' for anything else.
     */
    'database' => [
        'driver' => 'sqlsrv',
        'host' => 'localhost',
        'port' => 1433,
        'database' => 'app',
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
     *   php bin/console oauth:client:create "My Service"
     */
    'oauth2' => [
        'ttl' => 900, // 15 minutes
    ],
];
