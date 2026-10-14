<?php

declare(strict_types=1);

namespace bapersbw\Modules\Login;

use bapersbw\Auth\AuthManager;
use bapersbw\Auth\Identity;
use bapersbw\Auth\SessionGuard;
use bapersbw\Attributes\Route;
use bapersbw\Exceptions\HttpException;
use bapersbw\Http\Response;
use bapersbw\Webservice;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Outward authentication module, mounted under "login". Thin: provider
 * selection lives in {@see AuthManager} (config-driven), session handling in
 * {@see SessionGuard}, SAML/LDAP mechanics in the providers.
 *
 *   GET  /login                       begin login with the default provider
 *   GET  /login/logout                clear the session
 *   GET  /login/whoami                current identity (401 if none)
 *   GET  /login/{provider}            begin login with a named provider
 *   GET|POST /login/{provider}/callback   complete login -> session
 *
 * Literal routes are declared before "{provider}" so they win on match.
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
        $this->logger->info('User logged out', ['subject' => $identity?->id]);

        return $this->ok(['logged_out' => true]);
    }

    #[Route('GET', '/whoami')]
    public function whoami(): Response
    {
        $identity = $this->request->attribute('identity');
        if (!$identity instanceof Identity) {
            throw new HttpException(401, 'Not signed in.');
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
        } catch (HttpException $e) {
            $this->logger->warning('Login failed via {provider}: {reason}', [
                'provider' => $provider,
                'reason' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->guard->login($identity);
        $this->logger->info('Login succeeded via {provider}', [
            'provider' => $provider,
            'subject' => $identity->id,
            'type' => $identity->type,
        ]);

        return $this->ok(['authenticated' => true, 'identity' => $identity]);
    }
}
