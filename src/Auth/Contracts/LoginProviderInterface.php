<?php

declare(strict_types=1);

namespace bapersbw\Auth\Contracts;

use bapersbw\Auth\Exceptions\AuthenticationException;
use bapersbw\Auth\Identity;
use bapersbw\Http\Request;
use bapersbw\Http\Response;

/**
 * An outward-facing, human login provider (SAML, LDAP, ...).
 *
 * The flow is two-legged so it fits both redirect-based providers (SAML) and
 * credential-based ones (LDAP):
 *   - {@see initiate()} begins login (redirect to IdP, or present a form);
 *   - {@see complete()} turns the provider's response into an {@see Identity}.
 */
interface LoginProviderInterface
{
    /** Stable provider key used in config and URLs, e.g. "saml", "ldap". */
    public function name(): string;

    /** Begin the login flow. */
    public function initiate(Request $request): Response;

    /**
     * Complete the login flow from the provider's callback request.
     *
     * @throws AuthenticationException on failure.
     */
    public function complete(Request $request): Identity;

    /**
     * Begin provider-side logout (e.g. SAML Single Logout). Return null when
     * the provider has no remote logout, leaving session teardown to the caller.
     */
    public function logout(Request $request, ?Identity $identity): ?Response;
}
