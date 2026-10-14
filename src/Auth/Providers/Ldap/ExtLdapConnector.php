<?php

declare(strict_types=1);

namespace bapersbw\Auth\Providers\Ldap;

use RuntimeException;

/**
 * Real LDAP connector backed by the ext-ldap extension.
 *
 * It binds as the user to verify the password. The bind DN is built from
 * `dn_format` (e.g. "uid=%s,ou=people,dc=example,dc=com"); optionally, after a
 * successful bind, it searches for the user's entry to collect attributes.
 *
 * Config (config/config.php → auth.ldap):
 *   'host'       => 'ldap://ldap.example.com',
 *   'port'       => 389,
 *   'dn_format'  => 'uid=%s,ou=people,dc=example,dc=com',
 *   'base_dn'    => 'ou=people,dc=example,dc=com',   // optional, for attr search
 *   'attributes' => ['cn', 'mail', 'memberOf'],      // optional
 *   'start_tls'  => false,
 */
final class ExtLdapConnector implements LdapConnectorInterface
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
    ) {
    }

    public function authenticate(string $username, string $password): ?array
    {
        if (!extension_loaded('ldap')) {
            throw new RuntimeException(
                'The ldap extension is not installed. Use the dev connector ' .
                '(auth.ldap.fake = true) or install ext-ldap.',
            );
        }

        // Empty password with anonymous bind is a classic LDAP pitfall: reject.
        if ($password === '') {
            return null;
        }

        $uri = (string) ($this->config['host'] ?? '');
        $port = (int) ($this->config['port'] ?? 389);

        $connection = @ldap_connect($uri, $port);
        if ($connection === false) {
            throw new RuntimeException("Unable to connect to LDAP server [$uri].");
        }

        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);

        if (($this->config['start_tls'] ?? false) && !@ldap_start_tls($connection)) {
            throw new RuntimeException('LDAP StartTLS negotiation failed.');
        }

        $dn = sprintf((string) ($this->config['dn_format'] ?? '%s'), $username);

        if (!@ldap_bind($connection, $dn, $password)) {
            return null; // invalid credentials
        }

        $attributes = $this->fetchAttributes($connection, $dn, $username);

        ldap_unbind($connection);

        return [
            'username' => $username,
            'display_name' => $attributes['cn'][0] ?? $username,
            'roles' => $attributes['memberof'] ?? [],
            'attributes' => $attributes,
        ];
    }

    /**
     * @param \LDAP\Connection $connection
     * @return array<string, mixed>
     */
    private function fetchAttributes(mixed $connection, string $dn, string $username): array
    {
        $baseDn = (string) ($this->config['base_dn'] ?? '');
        if ($baseDn === '') {
            return [];
        }

        $wanted = $this->config['attributes'] ?? [];
        $filter = '(uid=' . ldap_escape($username, '', LDAP_ESCAPE_FILTER) . ')';

        $search = @ldap_search($connection, $baseDn, $filter, $wanted);
        if ($search === false) {
            return [];
        }

        $entries = ldap_get_entries($connection, $search);

        return isset($entries[0]) ? $this->normalise($entries[0]) : [];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function normalise(array $entry): array
    {
        $result = [];
        foreach ($entry as $key => $value) {
            if (!is_string($key) || $key === 'count') {
                continue;
            }
            if (is_array($value)) {
                unset($value['count']);
            }
            $result[strtolower($key)] = $value;
        }

        return $result;
    }
}
