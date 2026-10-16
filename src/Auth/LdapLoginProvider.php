<?php

declare(strict_types=1);

namespace bapersbw\Auth;

use bapersbw\Exceptions\HttpException;
use bapersbw\Http\Request;
use bapersbw\Http\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Credential-based login (the dev/fallback outward path).
 *
 * In `fake` mode it authenticates against users defined in config — no LDAP
 * server or ext-ldap needed. Otherwise it binds against a real directory via
 * ext-ldap. initiate() renders a minimal form; API clients may POST credentials
 * straight to the callback.
 *
 * @see config/config.php → auth.ldap
 */
final class LdapLoginProvider implements LoginProviderInterface
{
    private readonly LoggerInterface $logger;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function initiate(Request $request): Response
    {
        $returnTo = htmlspecialchars((string) $request->query('return_to', ''), ENT_QUOTES);
        $action = htmlspecialchars($request->url('/login/ldap/callback'), ENT_QUOTES);
        $html = <<<HTML
            <!doctype html><html lang="en"><head><meta charset="utf-8"><title>Sign in</title></head>
            <body><form method="post" action="{$action}">
              <input type="hidden" name="return_to" value="{$returnTo}">
              <label>Username <input name="username" autofocus></label><br>
              <label>Password <input name="password" type="password"></label><br>
              <button type="submit">Sign in</button>
            </form></body></html>
            HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function complete(Request $request): Identity
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        if ($username === '' || $password === '') {
            throw new HttpException(401, 'Username and password are required.');
        }

        $attributes = ($this->config['fake'] ?? false)
            ? $this->authenticateFake($username, $password)
            : $this->authenticateLdap($username, $password);

        if ($attributes === null) {
            throw new HttpException(401, 'Invalid credentials.');
        }

        return new Identity(
            id: $username,
            type: Identity::TYPE_USER,
            displayName: $attributes['display_name'] ?? $username,
            roles: $attributes['roles'] ?? [],
            attributes: $attributes['attributes'] ?? [],
            provider: 'ldap',
        );
    }

    /** @return array<string, mixed>|null */
    private function authenticateFake(string $username, string $password): ?array
    {
        $user = $this->config['users'][$username] ?? null;
        if ($user === null) {
            $this->logger->warning('LDAP (fake mode) auth failed: user {user} is not in the configured users list', ['user' => $username]);

            return null;
        }

        $stored = (string) ($user['password'] ?? '');
        $valid = str_starts_with($stored, '$')
            ? password_verify($password, $stored)
            : hash_equals($stored, $password);

        if (!$valid) {
            $this->logger->warning('LDAP (fake mode) auth failed: password mismatch for {user}', ['user' => $username]);

            return null;
        }

        return [
            'display_name' => $user['display_name'] ?? $username,
            'roles' => $user['roles'] ?? [],
            'attributes' => $user['attributes'] ?? [],
        ];
    }

    /** @return array<string, mixed>|null */
    private function authenticateLdap(string $username, string $password): ?array
    {
        if (!extension_loaded('ldap')) {
            throw new HttpException(500, 'ext-ldap is not installed; use auth.ldap.fake = true for development.');
        }
        if ($password === '') {
            return null; // avoid accidental anonymous bind
        }

        $connection = @ldap_connect((string) ($this->config['host'] ?? ''), (int) ($this->config['port'] ?? 389));
        if ($connection === false) {
            throw new HttpException(500, 'Unable to connect to the LDAP server (check host/port).');
        }
        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);

        if ($this->config['start_tls'] ?? false) {
            if (!@ldap_start_tls($connection)) {
                $this->logger->warning('LDAP StartTLS failed: {error}', ['error' => ldap_error($connection)]);
                throw new HttpException(500, 'LDAP StartTLS negotiation failed.');
            }
        }

        // Step 1: anonymous bind, then search by uid to discover the user's
        // real DN. We cannot construct it from a template because the entries
        // are indexed by uid but their DN is keyed on another attribute (cn).
        if (!@ldap_bind($connection)) {
            $this->logger->warning('LDAP anonymous bind (for DN lookup) failed: {error}', [
                'error' => ldap_error($connection),
            ]);

            return null;
        }

        $dn = $this->resolveUserDn($connection, $username);
        if ($dn === null) {
            $this->logger->warning('LDAP: no entry found for uid {user}', ['user' => $username]);

            return null;
        }

        // Step 2: bind as that DN with the supplied password to verify it.
        if (!@ldap_bind($connection, $dn, $password)) {
            // The real reason (bad credentials, server down, TLS, …)
            // — generic to the client, specific in the log.
            ldap_get_option($connection, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diagnostic);
            $this->logger->warning('LDAP bind failed for {dn}: {error} {diagnostic}', [
                'dn' => $dn,
                'error' => ldap_error($connection),
                'diagnostic' => (string) $diagnostic,
            ]);

            return null;
        }

        return array_merge([
            'display_name' => $username,
            'roles' => [],
            'attributes' => ['dn' => $dn],
        ], $this->fetchAttributes($connection, $dn, $username));
    }

    /**
     * Anonymous-search the directory by uid to find the user's real DN.
     *
     * @param \LDAP\Connection $connection
     */
    private function resolveUserDn(mixed $connection, string $username): ?string
    {
        $baseDn = (string) ($this->config['base_dn'] ?? '');
        if ($baseDn === '') {
            $this->logger->error('LDAP: auth.ldap.base_dn is required for DN lookup.');

            return null;
        }

        $filter = '(uid=' . ldap_escape($username, '', LDAP_ESCAPE_FILTER) . ')';
        $search = @ldap_search($connection, $baseDn, $filter, ['dn']);
        if ($search === false) {
            $this->logger->warning('LDAP search failed: {error}', ['error' => ldap_error($connection)]);

            return null;
        }

        $entries = ldap_get_entries($connection, $search);
        if (($entries['count'] ?? 0) < 1 || !isset($entries[0]['dn'])) {
            return null;
        }

        return (string) $entries[0]['dn'];
    }

    /**
     * Read the configured attributes for the user (optional). `attributes` is
     * the list to fetch; `base_dn` is searched if the bind DN can't be read
     * directly. cn → display_name, memberOf → roles when present.
     *
     * @param \LDAP\Connection $connection
     * @return array<string, mixed>
     */
    private function fetchAttributes(mixed $connection, string $dn, string $username): array
    {
        $wanted = (array) ($this->config['attributes'] ?? []);
        if ($wanted === []) {
            return [];
        }

        // Read the user's own entry by DN.
        $search = @ldap_read($connection, $dn, '(objectClass=*)', $wanted);
        if ($search === false) {
            // Fall back to a subtree search under base_dn by uid.
            $baseDn = (string) ($this->config['base_dn'] ?? '');
            if ($baseDn === '') {
                return [];
            }
            $filter = '(uid=' . ldap_escape($username, '', LDAP_ESCAPE_FILTER) . ')';
            $search = @ldap_search($connection, $baseDn, $filter, $wanted);
        }
        if ($search === false) {
            return [];
        }

        $entries = ldap_get_entries($connection, $search);
        if (!isset($entries[0])) {
            $this->logger->debug('LDAP returned no entry for {user} when fetching attributes', ['user' => $username]);

            return [];
        }

        $attrs = [];
        foreach ($entries[0] as $key => $value) {
            if (!is_string($key) || $key === 'count') {
                continue;
            }
            if (is_array($value)) {
                unset($value['count']);
            }
            $attrs[strtolower($key)] = $value;
        }

        // Debug: log exactly what the directory returned (names + values), with
        // any binary / non-UTF-8 values rendered safely so logging can't fail.
        $this->logger->debug('LDAP attributes returned for {user}: {attributes}', [
            'user' => $username,
            'attributes' => $this->loggableAttributes($attrs),
        ]);

        $result = ['attributes' => $attrs];
        if (isset($attrs['cn'][0])) {
            $result['display_name'] = $attrs['cn'][0];
        }
        if (isset($attrs['memberof'])) {
            $result['roles'] = array_values((array) $attrs['memberof']);
        }

        return $result;
    }

    /**
     * Render fetched attributes for logging: a map of name => values, with any
     * non-UTF-8 (binary) value replaced by a "base64:…" marker so the log line
     * is always valid, printable JSON.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, list<string>>
     */
    private function loggableAttributes(array $attrs): array
    {
        $safe = [];
        foreach ($attrs as $name => $value) {
            $values = is_array($value) ? array_values($value) : [$value];
            $safe[$name] = array_map(
                static function (mixed $v): string {
                    $v = (string) $v;

                    return preg_match('//u', $v) === 1 ? $v : 'base64:' . base64_encode($v);
                },
                $values,
            );
        }

        return $safe;
    }
}
