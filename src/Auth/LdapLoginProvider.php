<?php

declare(strict_types=1);

namespace bapersbw\Auth;

use bapersbw\Exceptions\HttpException;
use bapersbw\Http\Request;
use bapersbw\Http\Response;

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
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
    ) {
    }

    public function initiate(Request $request): Response
    {
        $returnTo = htmlspecialchars((string) $request->query('return_to', ''), ENT_QUOTES);
        $html = <<<HTML
            <!doctype html><html lang="en"><head><meta charset="utf-8"><title>Sign in</title></head>
            <body><form method="post" action="/login/ldap/callback">
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
            return null;
        }

        $stored = (string) ($user['password'] ?? '');
        $valid = str_starts_with($stored, '$')
            ? password_verify($password, $stored)
            : hash_equals($stored, $password);

        if (!$valid) {
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
            throw new HttpException(500, 'Unable to connect to the LDAP server.');
        }
        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);

        $dn = sprintf((string) ($this->config['dn_format'] ?? '%s'), $username);
        if (!@ldap_bind($connection, $dn, $password)) {
            return null;
        }

        return ['display_name' => $username, 'roles' => [], 'attributes' => ['dn' => $dn]];
    }
}
