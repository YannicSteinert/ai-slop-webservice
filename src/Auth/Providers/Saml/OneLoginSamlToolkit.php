<?php

declare(strict_types=1);

namespace bapersbw\Auth\Providers\Saml;

use OneLogin\Saml2\Auth as SamlAuth;
use RuntimeException;

/**
 * Adapter over the onelogin/php-saml toolkit.
 *
 * The onelogin settings array is passed straight through from config
 * (auth.saml.settings), so the full toolkit configuration surface — SP/IdP
 * entity ids, ACS/SLO URLs, certificates, security options — is available
 * without this class needing to know about any of it.
 *
 * The toolkit reads the SAMLResponse from PHP's superglobals, matching how it
 * is designed to be used behind a real HTTP request to the ACS endpoint.
 */
final class OneLoginSamlToolkit implements SamlToolkitInterface
{
    /** @param array<string, mixed> $settings onelogin/php-saml settings array. */
    public function __construct(
        private readonly array $settings,
    ) {
    }

    public function loginUrl(?string $returnTo = null): string
    {
        return $this->auth()->login($returnTo, [], false, false, true);
    }

    public function processResponse(): array
    {
        $auth = $this->auth();
        $auth->processResponse();

        $errors = $auth->getErrors();
        if ($errors !== []) {
            throw new RuntimeException(
                'SAML response error: ' . implode(', ', $errors) .
                ' — ' . (string) $auth->getLastErrorReason(),
            );
        }

        if (!$auth->isAuthenticated()) {
            throw new RuntimeException('SAML response was not authenticated.');
        }

        return [
            'name_id' => (string) $auth->getNameId(),
            'attributes' => $auth->getAttributes(),
            'session_index' => $auth->getSessionIndex(),
        ];
    }

    public function logoutUrl(?string $returnTo = null, ?string $nameId = null, ?string $sessionIndex = null): ?string
    {
        return $this->auth()->logout($returnTo, [], $nameId, $sessionIndex, true);
    }

    private function auth(): SamlAuth
    {
        try {
            return new SamlAuth($this->settings);
        } catch (\Throwable $e) {
            throw new RuntimeException('SAML is not correctly configured: ' . $e->getMessage(), 0, $e);
        }
    }
}
