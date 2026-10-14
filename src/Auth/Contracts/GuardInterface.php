<?php

declare(strict_types=1);

namespace bapersbw\Auth\Contracts;

use bapersbw\Auth\Identity;
use bapersbw\Http\Request;

/**
 * Resolves the identity already associated with a request, if any.
 *
 * A guard only *reads* existing credentials (a session cookie, a bearer token);
 * establishing them is the job of the login flow / token issuer. Resolution and
 * enforcement are deliberately separate — enforcement lives in policies.
 */
interface GuardInterface
{
    public function resolve(Request $request): ?Identity;
}
