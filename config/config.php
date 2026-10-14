<?php

declare(strict_types=1);

use bapersbw\Auth\AuthenticationMiddleware;
use bapersbw\Auth\Session\SessionMiddleware;
use bapersbw\Policy\AuthorizationMiddleware;

/**
 * Application configuration.
 */
return [
    // When true, 500 responses include the exception message and trace.
    'debug' => true,

    /**
     * Global middleware, run in order around every request. Each entry is a
     * class-string resolved through the container.
     *
     *   1. SessionMiddleware        start/persist the session
     *   2. AuthenticationMiddleware resolve the identity (token, then session)
     *   3. AuthorizationMiddleware  enforce #[Authorize] policies
     */
    'middleware' => [
        SessionMiddleware::class,
        AuthenticationMiddleware::class,
        AuthorizationMiddleware::class,
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
     * Inward (service-to-service) authentication: OAuth2 bearer tokens.
     *
     * The dev validator maps the tokens below to client identities. Replace
     * with OAuth2 introspection / JWT verification in production.
     */
    'oauth2' => [
        'tokens' => [
            'dev-token-onboarding' => [
                'client_id' => 'onboarding-app',
                'name' => 'Onboarding App',
                'scopes' => ['users.read'],
            ],
        ],
    ],
];
