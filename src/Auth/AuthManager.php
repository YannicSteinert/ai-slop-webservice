<?php

declare(strict_types=1);

namespace bapersbw\Auth;

use bapersbw\Exceptions\HttpException;
use bapersbw\Logging\SecurityLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds and selects the outward login providers from config.
 *
 * `default` is the provider used by GET /login (LDAP for dev/fallback, SAML in
 * production); `enabled` gates which providers may be used at all.
 *
 * @see config/config.php → auth
 */
final class AuthManager
{
    /** @var array<string, LoginProviderInterface> */
    private array $resolved = [];

    private readonly LoggerInterface $logger;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        ?SecurityLogger $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function default(): LoginProviderInterface
    {
        return $this->provider((string) ($this->config['default'] ?? 'ldap'));
    }

    public function provider(string $name): LoginProviderInterface
    {
        $name = strtolower($name);

        $enabled = array_map('strtolower', (array) ($this->config['enabled'] ?? [$this->config['default'] ?? 'ldap']));
        if (!in_array($name, $enabled, true)) {
            throw new HttpException(404, "Login provider [$name] is not enabled.");
        }

        return $this->resolved[$name] ??= $this->build($name);
    }

    private function build(string $name): LoginProviderInterface
    {
        $config = (array) ($this->config[$name] ?? []);

        return match ($name) {
            'ldap' => new LdapLoginProvider($config, $this->logger),
            'saml' => new SamlLoginProvider($config),
            default => throw new HttpException(404, "Unknown login provider [$name]."),
        };
    }
}
