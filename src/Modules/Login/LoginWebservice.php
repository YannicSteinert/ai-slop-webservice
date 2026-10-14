<?php

declare(strict_types=1);

namespace bapersbw\Modules\Login;

use bapersbw\Attributes\Route;
use bapersbw\Auth\AuthManager;
use bapersbw\Auth\Exceptions\AuthenticationException;
use bapersbw\Auth\Guards\SessionGuard;
use bapersbw\Auth\Identity;
use bapersbw\Http\Response;
use bapersbw\Webservice\Webservice;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Outward authentication module, mounted under "login".
 *
 * It orchestrates the provider flow but stays thin: provider selection lives in
 * {@see AuthManager} (config-driven), session handling in {@see SessionGuard},
 * and the SAML/LDAP mechanics in the providers themselves.
 *
 *   GET  /login                       -> start()     begin login with the default provider
 *   GET  /login/logout                -> logout()
 *   GET  /login/whoami                -> whoami()     current session identity (debug/test aid)
 *   GET  /login/{provider}            -> begin()      begin login with a named provider
 *   GET  /login/{provider}/callback   -> callback()   (SAML redirect binding)
 *   POST /login/{provider}/callback   -> callback()   (SAML POST binding / LDAP form)
 *
 * The literal routes are declared before the "{provider}" route so they win on
 * match (the router returns the first matching route in declaration order).
 */
final class LoginWebservice extends Webservice
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly AuthManager $auth,
        private readonly SessionGuard $guard,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    #[Route('GET', '/')]
    public function start(): Response
    {
        return $this->auth->default()->initiate($this->request);
    }

    #[Route('GET', '/logout')]
    public function logout(): Response
    {
        $identity = $this->guard->user();
        $this->guard->logout();

        $this->logger->info('User logged out', [
            'request_id' => $this->request->attribute('request_id'),
            'subject' => $identity?->id,
        ]);

        return $this->ok(['logged_out' => true]);
    }

    #[Route('GET', '/whoami')]
    public function whoami(): Response
    {
        $identity = $this->request->attribute('identity');
        if (!$identity instanceof Identity) {
            throw new AuthenticationException('Not signed in.');
        }

        return $this->ok(['identity' => $identity]);
    }

    #[Route('GET', '/{provider}')]
    public function begin(string $provider): Response
    {
        return $this->auth->provider($provider)->initiate($this->request);
    }

    #[Route('GET', '/{provider}/callback')]
    #[Route('POST', '/{provider}/callback')]
    public function callback(string $provider): Response
    {
        try {
            $identity = $this->auth->provider($provider)->complete($this->request);
        } catch (AuthenticationException $e) {
            $this->logger->warning('Login failed via {provider}: {reason}', [
                'request_id' => $this->request->attribute('request_id'),
                'provider' => $provider,
                'reason' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Establish the outward session.
        $this->guard->login($identity);

        $this->logger->info('Login succeeded via {provider}', [
            'request_id' => $this->request->attribute('request_id'),
            'provider' => $provider,
            'subject' => $identity->id,
            'type' => $identity->type,
        ]);

        // API-first: return the identity as JSON. A browser-facing deployment
        // can switch this to a redirect to `return_to` instead.
        return $this->ok([
            'authenticated' => true,
            'identity' => $identity,
        ]);
    }
}
