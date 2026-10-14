<?php

declare(strict_types=1);

namespace bapersbw\Auth;

use bapersbw\Auth\Contracts\LoginProviderInterface;
use bapersbw\Auth\Providers\Ldap\ArrayLdapConnector;
use bapersbw\Auth\Providers\Ldap\ExtLdapConnector;
use bapersbw\Auth\Providers\Ldap\LdapConnectorInterface;
use bapersbw\Auth\Providers\Ldap\LdapLoginProvider;
use bapersbw\Auth\Providers\Saml\OneLoginSamlToolkit;
use bapersbw\Auth\Providers\Saml\SamlLoginProvider;
use bapersbw\Exceptions\HttpException;
use InvalidArgumentException;

/**
 * Builds and selects the outward login providers from config.
 *
 * config/config.php → auth:
 *   'default'  => 'ldap',               // provider used by GET /login
 *   'enabled'  => ['ldap', 'saml'],     // providers that may be used at all
 *   'ldap'     => [...],
 *   'saml'     => ['settings' => [...]],
 *
 * "default" is what makes LDAP a drop-in development/fallback login while SAML
 * is the production default — flip one config value, nothing else changes.
 *
 * New provider types are added via {@see extend()} or a new case in build().
 */
final class AuthManager
{
    /** @var array<string, LoginProviderInterface> */
    private array $resolved = [];

    /** @var array<string, callable(array<string, mixed>): LoginProviderInterface> */
    private array $factories = [];

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
    ) {
    }

    /**
     * Register a custom provider factory, keyed by provider name.
     *
     * @param callable(array<string, mixed>): LoginProviderInterface $factory
     */
    public function extend(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;
    }

    public function defaultName(): string
    {
        return (string) ($this->config['default'] ?? 'ldap');
    }

    /** @return list<string> */
    public function enabled(): array
    {
        $enabled = $this->config['enabled'] ?? [$this->defaultName()];

        return array_values(array_map('strval', (array) $enabled));
    }

    public function isEnabled(string $name): bool
    {
        return in_array(strtolower($name), array_map('strtolower', $this->enabled()), true);
    }

    public function default(): LoginProviderInterface
    {
        return $this->provider($this->defaultName());
    }

    public function provider(string $name): LoginProviderInterface
    {
        $name = strtolower($name);

        if (!$this->isEnabled($name)) {
            throw new HttpException(404, "Login provider [$name] is not enabled.");
        }

        return $this->resolved[$name] ??= $this->build($name);
    }

    private function build(string $name): LoginProviderInterface
    {
        $config = (array) ($this->config[$name] ?? []);

        if (isset($this->factories[$name])) {
            return ($this->factories[$name])($config);
        }

        return match ($name) {
            'ldap' => new LdapLoginProvider($this->ldapConnector($config)),
            'saml' => new SamlLoginProvider(
                new OneLoginSamlToolkit((array) ($config['settings'] ?? [])),
                $config,
            ),
            default => throw new InvalidArgumentException("No factory for login provider [$name]."),
        };
    }

    /** @param array<string, mixed> $config */
    private function ldapConnector(array $config): LdapConnectorInterface
    {
        if ($config['fake'] ?? false) {
            return new ArrayLdapConnector((array) ($config['users'] ?? []));
        }

        return new ExtLdapConnector($config);
    }
}
