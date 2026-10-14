<?php

declare(strict_types=1);

namespace bapersbw\Auth;

use bapersbw\Http\Request;

/**
 * The inward guard: resolves a client identity from an OAuth2 bearer token.
 *
 * For development it validates against a token map from config. To use real
 * OAuth2 (introspection or JWT) in production, replace this class — it is the
 * single seam for inward auth.
 */
final class TokenGuard
{
    /** @param array<string, array<string, mixed>> $tokens token => client metadata */
    public function __construct(
        private readonly array $tokens,
    ) {
    }

    public function resolve(Request $request): ?Identity
    {
        $header = $request->header('authorization');
        if ($header === null || !preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return null;
        }

        $token = trim($m[1]);
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
