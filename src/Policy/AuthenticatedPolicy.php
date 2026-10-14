<?php

declare(strict_types=1);

namespace bapersbw\Policy;

use bapersbw\Auth\Identity;
use bapersbw\Http\Request;

/**
 * Passes when the request carries an authenticated identity, optionally
 * constrained to a type ("user"/"client") and/or required roles.
 *
 *   #[Authorize('authenticated')]                    any identity
 *   #[Authorize('authenticated', 'client')]          inward service only
 *   #[Authorize('authenticated', 'user', 'admin')]   human with the admin role
 */
final class AuthenticatedPolicy implements PolicyInterface
{
    public function authorize(Request $request, array $arguments): bool
    {
        $identity = $request->attribute('identity');
        if (!$identity instanceof Identity) {
            return false;
        }

        foreach ($arguments as $argument) {
            $value = (string) $argument;
            if (in_array($value, [Identity::TYPE_USER, Identity::TYPE_CLIENT], true)) {
                if ($identity->type !== $value) {
                    return false;
                }
            } elseif (!$identity->hasRole($value)) {
                return false;
            }
        }

        return true;
    }
}
