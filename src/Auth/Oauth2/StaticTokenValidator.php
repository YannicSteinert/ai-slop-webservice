<?php

declare(strict_types=1);

namespace bapersbw\Auth\Oauth2;

use bapersbw\Auth\Contracts\TokenValidatorInterface;
use bapersbw\Auth\Identity;

/**
 * Development inward-token validator: maps opaque tokens defined in config to
 * client identities. Intended for local development and tests.
 *
 * Config (config/config.php → oauth2.tokens):
 *   'dev-token-abc' => [
 *       'client_id' => 'onboarding-app',
 *       'name'      => 'Onboarding App',
 *       'scopes'    => ['users.read'],
 *   ]
 *
 * Replace with an OAuth2 introspection or JWT validator in production — same
 * interface, no other changes.
 */
final class StaticTokenValidator implements TokenValidatorInterface
{
    /** @param array<string, array<string, mixed>> $tokens */
    public function __construct(
        private readonly array $tokens,
    ) {
    }

    public function validate(string $token): ?Identity
    {
        foreach ($this->tokens as $candidate => $client) {
            if (hash_equals((string) $candidate, $token)) {
                return new Identity(
                    id: (string) ($client['client_id'] ?? $candidate),
                    type: Identity::TYPE_CLIENT,
                    displayName: $client['name'] ?? null,
                    roles: $client['scopes'] ?? [],
                    attributes: $client,
                    provider: 'oauth2',
                );
            }
        }

        return null;
    }
}
