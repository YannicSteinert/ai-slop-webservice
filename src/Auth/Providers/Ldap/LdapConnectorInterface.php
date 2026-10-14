<?php

declare(strict_types=1);

namespace bapersbw\Auth\Providers\Ldap;

/**
 * Verifies a username/password pair against a directory.
 *
 * Two implementations ship: {@see ExtLdapConnector} (real LDAP via ext-ldap)
 * and {@see ArrayLdapConnector} (config-defined users, the dev/fallback mode).
 * Which one is used is decided by config, so switching between a live directory
 * and local development is a configuration change, not a code change.
 */
interface LdapConnectorInterface
{
    /**
     * @return array<string, mixed>|null User attributes on success, null on failure.
     */
    public function authenticate(string $username, string $password): ?array;
}
