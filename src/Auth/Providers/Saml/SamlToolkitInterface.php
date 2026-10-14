<?php

declare(strict_types=1);

namespace bapersbw\Auth\Providers\Saml;

/**
 * Thin seam over a SAML2 engine. {@see OneLoginSamlToolkit} implements it with
 * the onelogin/php-saml toolkit; tests (or an alternative engine) can provide
 * their own implementation without touching {@see SamlLoginProvider}.
 */
interface SamlToolkitInterface
{
    /** Build the AuthnRequest and return the IdP SSO URL to redirect to. */
    public function loginUrl(?string $returnTo = null): string;

    /**
     * Process the SAMLResponse from the ACS callback.
     *
     * @return array{name_id: string, attributes: array<string, mixed>, session_index: ?string}
     * @throws \RuntimeException when the response is missing, invalid or not authenticated.
     */
    public function processResponse(): array;

    /** Build the LogoutRequest and return the IdP SLO URL, or null if unsupported. */
    public function logoutUrl(?string $returnTo = null, ?string $nameId = null, ?string $sessionIndex = null): ?string;
}
