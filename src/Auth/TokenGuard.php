<?php

declare(strict_types=1);

namespace bapersbw\Auth;

use bapersbw\Http\Request;

/**
 * The inward guard: resolves a client identity from an OAuth2 bearer token by
 * validating it against the {@see TokenStore} (the database).
 *
 * It short-circuits when there is no bearer header, so ordinary requests
 * (browser sessions, anonymous calls) never touch the token store or the
 * database.
 */
final class TokenGuard
{
    public function __construct(
        private readonly TokenStore $tokens,
    ) {
    }

    public function resolve(Request $request): ?Identity
    {
        $header = $request->header('authorization');
        if ($header === null || !preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return null;
        }

        return $this->tokens->validate(trim($m[1]));
    }
}
