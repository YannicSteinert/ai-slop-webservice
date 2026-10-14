<?php

declare(strict_types=1);

namespace bapersbw\Auth\Guards;

use bapersbw\Auth\Contracts\GuardInterface;
use bapersbw\Auth\Contracts\TokenValidatorInterface;
use bapersbw\Auth\Identity;
use bapersbw\Http\Request;

/**
 * The inward guard: resolves a client identity from an OAuth2 bearer token in
 * the Authorization header. Validation is delegated to a
 * {@see TokenValidatorInterface}, so the token format (opaque + introspection,
 * or JWT) is a pluggable concern.
 */
final class TokenGuard implements GuardInterface
{
    public function __construct(
        private readonly TokenValidatorInterface $validator,
    ) {
    }

    public function resolve(Request $request): ?Identity
    {
        $header = $request->header('authorization');
        if ($header === null || !preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return null;
        }

        return $this->validator->validate(trim($m[1]));
    }
}
