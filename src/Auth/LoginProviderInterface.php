<?php

declare(strict_types=1);

namespace bapersbw\Auth;

use bapersbw\Http\Request;
use bapersbw\Http\Response;

/**
 * An outward, human login provider (SAML or LDAP).
 *
 *   initiate() begins login (redirect to IdP, or present a form)
 *   complete() turns the provider's callback into an Identity, or throws
 *              HttpException(401) on failure.
 */
interface LoginProviderInterface
{
    public function initiate(Request $request): Response;

    public function complete(Request $request): Identity;
}
