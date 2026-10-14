<?php

declare(strict_types=1);

namespace bapersbw\Auth;

use bapersbw\Exceptions\HttpException;
use bapersbw\Http\Request;
use bapersbw\Http\Response;
use OneLogin\Saml2\Auth as SamlAuth;
use Throwable;

/**
 * Redirect-based SAML login (the production outward path), backed directly by
 * onelogin/php-saml.
 *
 *   initiate() -> redirect to the IdP with an AuthnRequest
 *   complete() -> process the SAMLResponse posted back to the ACS endpoint
 *
 * The onelogin settings array is passed straight through from config
 * (auth.saml.settings); the toolkit reads the SAMLResponse from the request
 * superglobals, as it is designed to.
 *
 * @see config/config.php → auth.saml
 */
final class SamlLoginProvider implements LoginProviderInterface
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
    ) {
    }

    public function initiate(Request $request): Response
    {
        $returnTo = $request->query('return_to');

        return Response::redirect($this->auth()->login(is_string($returnTo) ? $returnTo : null, [], false, false, true));
    }

    public function complete(Request $request): Identity
    {
        $auth = $this->auth();

        try {
            $auth->processResponse();
        } catch (Throwable $e) {
            throw new HttpException(401, 'SAML authentication failed: ' . $e->getMessage());
        }

        if ($auth->getErrors() !== [] || !$auth->isAuthenticated()) {
            throw new HttpException(401, 'SAML authentication failed: ' . (string) $auth->getLastErrorReason());
        }

        $attributes = $auth->getAttributes();

        return new Identity(
            id: (string) $auth->getNameId(),
            type: Identity::TYPE_USER,
            displayName: $this->first($attributes, ['displayName', 'cn']) ?? (string) $auth->getNameId(),
            roles: array_values((array) ($attributes['memberOf'] ?? $attributes['groups'] ?? [])),
            attributes: $attributes,
            provider: 'saml',
        );
    }

    private function auth(): SamlAuth
    {
        try {
            return new SamlAuth((array) ($this->config['settings'] ?? []));
        } catch (Throwable $e) {
            throw new HttpException(500, 'SAML is not correctly configured: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string>         $keys
     */
    private function first(array $attributes, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($attributes[$key])) {
                $value = $attributes[$key];

                return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }

        return null;
    }
}
