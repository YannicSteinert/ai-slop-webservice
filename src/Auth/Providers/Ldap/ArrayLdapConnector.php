<?php

declare(strict_types=1);

namespace bapersbw\Auth\Providers\Ldap;

/**
 * Development / fallback connector: authenticates against a fixed set of users
 * defined in config. No LDAP server (or ext-ldap) required.
 *
 * Each user entry looks like:
 *   'devuser' => [
 *       'password'     => 'secret',              // plain, or a password_hash() string
 *       'display_name' => 'Dev User',
 *       'roles'        => ['admin'],
 *       'attributes'   => ['email' => 'dev@example.test'],
 *   ]
 */
final class ArrayLdapConnector implements LdapConnectorInterface
{
    /** @param array<string, array<string, mixed>> $users */
    public function __construct(
        private readonly array $users,
    ) {
    }

    public function authenticate(string $username, string $password): ?array
    {
        $user = $this->users[$username] ?? null;
        if ($user === null) {
            return null;
        }

        $stored = (string) ($user['password'] ?? '');
        if (!$this->verify($password, $stored)) {
            return null;
        }

        return [
            'username' => $username,
            'display_name' => $user['display_name'] ?? $username,
            'roles' => $user['roles'] ?? [],
            'attributes' => $user['attributes'] ?? [],
        ];
    }

    private function verify(string $password, string $stored): bool
    {
        // Treat values that look like a crypt hash as hashed; otherwise compare
        // in constant time to avoid leaking length/positions via timing.
        if ($stored !== '' && str_starts_with($stored, '$')) {
            return password_verify($password, $stored);
        }

        return hash_equals($stored, $password);
    }
}
