<?php

declare(strict_types=1);

namespace bapersbw\Auth\Contracts;

use bapersbw\Auth\Identity;

/**
 * Validates an inward OAuth2 bearer token and maps it to a client identity.
 *
 * The default {@see \bapersbw\Auth\Oauth2\StaticTokenValidator} reads tokens
 * from config for development. A production resource server (OAuth2
 * introspection, or local JWT verification) implements this same interface and
 * drops in via the container — nothing else changes.
 */
interface TokenValidatorInterface
{
    /** Return the client identity for a valid token, or null if invalid. */
    public function validate(string $token): ?Identity;
}
