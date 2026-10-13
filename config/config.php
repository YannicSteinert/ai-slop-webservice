<?php

declare(strict_types=1);

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
     * Authentication middleware (populating the user) would sit before
     * AuthorizationMiddleware once login is implemented.
     */
    'middleware' => [
        AuthorizationMiddleware::class,
    ],

    /**
     * Login providers — placeholders until SAML/LDAP are implemented.
     */
    'auth' => [
        'default_provider' => 'saml',
        'saml' => [
            // 'idp_metadata_url' => '...',
        ],
        'ldap' => [
            // 'host' => '...',
            // 'base_dn' => '...',
        ],
    ],
];
