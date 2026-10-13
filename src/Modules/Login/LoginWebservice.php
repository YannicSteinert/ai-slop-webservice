<?php

declare(strict_types=1);

namespace bapersbw\Modules\Login;

use bapersbw\Attributes\Route;
use bapersbw\Exceptions\HttpException;
use bapersbw\Http\Response;
use bapersbw\Webservice\Webservice;

/**
 * Login module, mounted under "login".
 *
 * It decides between SAML and LDAP and redirects the client to the right flow.
 * Both providers are stubbed for now — the branching and redirect contract are
 * in place so the real implementations drop straight in behind {@see start()}.
 *
 *   GET /login                 -> start()   (picks provider, redirects)
 *   GET /login/{provider}      -> provider() (explicit provider choice)
 */
final class LoginWebservice extends Webservice
{
    private const PROVIDERS = ['saml', 'ldap'];

    #[Route('GET', '/')]
    public function start(): Response
    {
        $provider = $this->resolveProvider((string) $this->request->query('provider', $this->defaultProvider()));

        return $this->redirectToProvider($provider);
    }

    #[Route('GET', '/{provider}')]
    public function provider(string $provider): Response
    {
        return $this->redirectToProvider($this->resolveProvider($provider));
    }

    private function redirectToProvider(string $provider): Response
    {
        // TODO: implement the real SAML / LDAP flows. For now we redirect to a
        // placeholder endpoint so the routing + provider selection is exercised.
        return match ($provider) {
            'saml' => $this->redirect('/login/saml/initiate'),
            'ldap' => $this->redirect('/login/ldap/form'),
        };
    }

    private function resolveProvider(string $provider): string
    {
        $provider = strtolower($provider);
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new HttpException(400, "Unsupported login provider [$provider].");
        }

        return $provider;
    }

    private function defaultProvider(): string
    {
        return 'saml';
    }
}
