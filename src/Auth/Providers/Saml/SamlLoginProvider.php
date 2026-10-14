<?php

declare(strict_types=1);

namespace bapersbw\Auth\Providers\Saml;

use bapersbw\Auth\Contracts\LoginProviderInterface;
use bapersbw\Auth\Exceptions\AuthenticationException;
use bapersbw\Auth\Identity;
use bapersbw\Http\RedirectResponse;
use bapersbw\Http\Request;
use bapersbw\Http\Response;

/**
 * Redirect-based SAML login provider (the outward, production path).
 *
 *   initiate() -> redirect to the IdP with an AuthnRequest
 *   complete() -> process the SAMLResponse posted back to the ACS endpoint
 *
 * All SAML mechanics live behind {@see SamlToolkitInterface}; this class only
 * maps the toolkit's result onto an {@see Identity}.
 */
final class SamlLoginProvider implements LoginProviderInterface
{
    /**
     * @param array<string, mixed> $config auth.saml config (attribute mapping, etc.)
     */
    public function __construct(
        private readonly SamlToolkitInterface $toolkit,
        private readonly array $config = [],
    ) {
    }

    public function name(): string
    {
        return 'saml';
    }

    public function initiate(Request $request): Response
    {
        $returnTo = $request->query('return_to');
        $returnTo = is_string($returnTo) ? $returnTo : null;

        return new RedirectResponse($this->toolkit->loginUrl($returnTo));
    }

    public function complete(Request $request): Identity
    {
        try {
            $data = $this->toolkit->processResponse();
        } catch (\RuntimeException $e) {
            throw new AuthenticationException('SAML authentication failed: ' . $e->getMessage());
        }

        $attributes = $data['attributes'];

        return new Identity(
            id: $data['name_id'],
            type: Identity::TYPE_USER,
            displayName: $this->firstAttribute($attributes, $this->mapping('display_name', ['displayName', 'cn'])) ?? $data['name_id'],
            roles: $this->roles($attributes),
            attributes: array_merge($attributes, ['_session_index' => $data['session_index']]),
            provider: $this->name(),
        );
    }

    public function logout(Request $request, ?Identity $identity): ?Response
    {
        $url = $this->toolkit->logoutUrl(
            returnTo: null,
            nameId: $identity?->id,
            sessionIndex: is_string($identity?->attribute('_session_index')) ? $identity->attribute('_session_index') : null,
        );

        return $url !== null ? new RedirectResponse($url) : null;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return list<string>
     */
    private function roles(array $attributes): array
    {
        foreach ($this->mapping('roles', ['roles', 'memberOf', 'groups']) as $key) {
            if (isset($attributes[$key])) {
                return array_values((array) $attributes[$key]);
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string>         $keys
     */
    private function firstAttribute(array $attributes, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($attributes[$key])) {
                $value = $attributes[$key];

                return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }

        return null;
    }

    /**
     * Resolve an attribute-name mapping from config, falling back to defaults.
     *
     * @param list<string> $default
     * @return list<string>
     */
    private function mapping(string $key, array $default): array
    {
        $mapped = $this->config['attribute_map'][$key] ?? null;
        if ($mapped === null) {
            return $default;
        }

        return array_values((array) $mapped);
    }
}
