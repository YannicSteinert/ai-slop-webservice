<?php

declare(strict_types=1);

namespace bapersbw\Auth\Providers\Ldap;

use bapersbw\Auth\Contracts\LoginProviderInterface;
use bapersbw\Auth\Exceptions\AuthenticationException;
use bapersbw\Auth\Identity;
use bapersbw\Http\Request;
use bapersbw\Http\Response;

/**
 * Credential-based login provider (the outward, dev/fallback path).
 *
 * {@see initiate()} presents a minimal login form that posts back to the
 * provider callback; {@see complete()} reads the credentials and verifies them
 * through the configured {@see LdapConnectorInterface}. API clients may post
 * credentials directly to the callback and skip the form.
 */
final class LdapLoginProvider implements LoginProviderInterface
{
    public function __construct(
        private readonly LdapConnectorInterface $connector,
    ) {
    }

    public function name(): string
    {
        return 'ldap';
    }

    public function initiate(Request $request): Response
    {
        $action = '/login/ldap/callback';
        $returnTo = htmlspecialchars((string) $request->query('return_to', ''), ENT_QUOTES);

        $html = <<<HTML
            <!doctype html>
            <html lang="en"><head><meta charset="utf-8"><title>Sign in</title></head>
            <body>
              <form method="post" action="{$action}">
                <input type="hidden" name="return_to" value="{$returnTo}">
                <label>Username <input name="username" autofocus></label><br>
                <label>Password <input name="password" type="password"></label><br>
                <button type="submit">Sign in</button>
              </form>
            </body></html>
            HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function complete(Request $request): Identity
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        if ($username === '' || $password === '') {
            throw new AuthenticationException('Username and password are required.');
        }

        $result = $this->connector->authenticate($username, $password);
        if ($result === null) {
            throw new AuthenticationException('Invalid credentials.');
        }

        return new Identity(
            id: (string) ($result['username'] ?? $username),
            type: Identity::TYPE_USER,
            displayName: $result['display_name'] ?? $username,
            roles: $result['roles'] ?? [],
            attributes: $result['attributes'] ?? [],
            provider: $this->name(),
        );
    }

    public function logout(Request $request, ?Identity $identity): ?Response
    {
        // LDAP has no remote logout; the session guard tears the session down.
        return null;
    }
}
