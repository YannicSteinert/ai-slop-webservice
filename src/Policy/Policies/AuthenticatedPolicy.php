<?php

declare(strict_types=1);

namespace bapersbw\Policy\Policies;

use bapersbw\Auth\Identity;
use bapersbw\Http\Request;
use bapersbw\Policy\PolicyInterface;

/**
 * Passes when the request carries an authenticated identity (user or client),
 * optionally constrained to a type and/or required roles.
 *
 * Usage:
 *   #[Authorize('authenticated')]                 // any identity
 *   #[Authorize('authenticated', 'user')]         // human only
 *   #[Authorize('authenticated', 'client')]       // service only
 *   #[Authorize('authenticated', 'user', 'admin')]// human with the admin role
 */
final class AuthenticatedPolicy implements PolicyInterface
{
    public function authorize(Request $request, array $arguments): bool
    {
        $identity = $request->attribute('identity');
        if (!$identity instanceof Identity) {
            return false;
        }

        [$type, $roles] = $this->split($arguments);

        if ($type !== null && $identity->type !== $type) {
            return false;
        }

        foreach ($roles as $role) {
            if (!$identity->hasRole($role)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string|int|float|bool> $arguments
     * @return array{0: ?string, 1: list<string>}
     */
    private function split(array $arguments): array
    {
        $type = null;
        $roles = [];

        foreach ($arguments as $argument) {
            $value = (string) $argument;
            if (in_array($value, [Identity::TYPE_USER, Identity::TYPE_CLIENT], true)) {
                $type = $value;
            } else {
                $roles[] = $value;
            }
        }

        return [$type, $roles];
    }
}
