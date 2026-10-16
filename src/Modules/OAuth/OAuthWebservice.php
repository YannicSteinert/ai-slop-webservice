<?php

declare(strict_types=1);

namespace bapersbw\Modules\OAuth;

use bapersbw\Attributes\Route;
use bapersbw\Auth\Identity;
use bapersbw\Auth\TokenStore;
use bapersbw\Http\Response;
use bapersbw\Logging\SecurityLogger;
use bapersbw\Webservice;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * OAuth2 endpoints, mounted under "oauth".
 *
 *   GET  /oauth/authorize   authorization-code flow: authenticate the user
 *                           (via the outward session), issue a code, redirect
 *                           back to the client with ?code=…&state=…
 *   POST /oauth/token       exchange credentials/code/refresh for tokens:
 *                             grant_type=client_credentials
 *                             grant_type=authorization_code   (+ PKCE)
 *                             grant_type=refresh_token
 *
 * Client authentication on /token accepts HTTP Basic (client_id:client_secret)
 * or the same pair in the body. The authorization-code flow also enforces PKCE
 * when the client sent a code_challenge.
 */
final class OAuthWebservice extends Webservice
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly TokenStore $tokens,
        ?SecurityLogger $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    #[Route('GET', '/authorize')]
    public function authorize(): Response
    {
        $clientId = (string) $this->request->query('client_id', '');
        $redirectUri = (string) $this->request->query('redirect_uri', '');

        // Can't safely redirect errors to an unverified client/URI.
        if ($clientId === '' || !$this->tokens->clientExists($clientId)) {
            return $this->json(['error' => 'invalid_client'], 400);
        }
        if ($redirectUri === '' || !$this->tokens->redirectUriAllowed($clientId, $redirectUri)) {
            return $this->json(['error' => 'invalid_request', 'error_description' => 'redirect_uri not registered'], 400);
        }

        $state = $this->request->query('state');

        if ((string) $this->request->query('response_type', '') !== 'code') {
            return $this->redirect($this->appendQuery($redirectUri, ['error' => 'unsupported_response_type', 'state' => $state]));
        }

        $codeChallenge = $this->nullIfEmpty($this->request->query('code_challenge'));

        // Public clients (SPAs) have no secret, so PKCE is their only protection:
        // require a code_challenge up front rather than issuing a bypassable code.
        if ($codeChallenge === null && $this->tokens->isPublicClient($clientId)) {
            return $this->redirect($this->appendQuery($redirectUri, [
                'error' => 'invalid_request',
                'error_description' => 'code_challenge required',
                'state' => $state,
            ]));
        }

        // Require a logged-in human (established by SAML/LDAP → session).
        $identity = $this->request->attribute('identity');
        if (!$identity instanceof Identity || $identity->type !== Identity::TYPE_USER) {
            // Build URLs under the app's mount path so the browser stays in this app.
            $returnTo = $this->request->url('/oauth/authorize') . '?' . http_build_query($this->request->query);

            return $this->redirect($this->request->url('/login') . '?return_to=' . rawurlencode($returnTo));
        }

        $code = $this->tokens->createAuthCode(
            $clientId,
            $redirectUri,
            $identity,
            $codeChallenge,
            $this->nullIfEmpty($this->request->query('code_challenge_method')),
        );

        $this->logger->info('OAuth code issued for client {client}', [
            'request_id' => $this->request->attribute('request_id'),
            'client' => $clientId,
            'subject' => $identity->id,
        ]);

        return $this->redirect($this->appendQuery($redirectUri, ['code' => $code, 'state' => $state]));
    }

    #[Route('POST', '/token')]
    public function token(): Response
    {
        [$clientId, $secret] = $this->clientCredentials();
        $grant = (string) $this->request->input('grant_type', '');

        if ($clientId === '' || !$this->tokens->clientExists($clientId)) {
            return $this->invalidClient($clientId);
        }

        if ($this->tokens->isPublicClient($clientId)) {
            // Public (secret-less) client: PKCE-protected user flows only. PKCE
            // itself is enforced at /authorize and re-checked on code redemption.
            if ($grant !== 'authorization_code' && $grant !== 'refresh_token') {
                $this->logger->warning('Public client {client} attempted grant {grant}', [
                    'request_id' => $this->request->attribute('request_id'),
                    'client' => $clientId,
                    'grant' => $grant,
                ]);

                return $this->json(['error' => 'unauthorized_client'], 400);
            }
        } elseif (!$this->tokens->authenticateClient($clientId, $secret)) {
            return $this->invalidClient($clientId);
        }

        return match ($grant) {
            'client_credentials' => $this->json($this->tokens->issueClientToken($clientId)),
            'authorization_code' => $this->grantAuthorizationCode($clientId),
            'refresh_token' => $this->grantRefreshToken($clientId),
            default => $this->json(['error' => 'unsupported_grant_type'], 400),
        };
    }

    private function grantAuthorizationCode(string $clientId): Response
    {
        $result = $this->tokens->redeemAuthCode(
            (string) $this->request->input('code', ''),
            $clientId,
            (string) $this->request->input('redirect_uri', ''),
            $this->nullIfEmpty($this->request->input('code_verifier')),
        );

        return $result === null
            ? $this->json(['error' => 'invalid_grant'], 400)
            : $this->json($result);
    }

    private function grantRefreshToken(string $clientId): Response
    {
        $result = $this->tokens->refresh((string) $this->request->input('refresh_token', ''), $clientId);

        return $result === null
            ? $this->json(['error' => 'invalid_grant'], 400)
            : $this->json($result);
    }

    private function invalidClient(string $clientId): Response
    {
        $this->logger->warning('OAuth client authentication failed for {client}', [
            'request_id' => $this->request->attribute('request_id'),
            'client' => $clientId,
        ]);

        return $this->json(['error' => 'invalid_client'], 401)->withHeader('WWW-Authenticate', 'Basic');
    }

    /**
     * Extract client credentials from the Authorization: Basic header, falling
     * back to body parameters.
     *
     * @return array{0: string, 1: string} [client_id, client_secret]
     */
    private function clientCredentials(): array
    {
        $header = (string) $this->request->header('authorization', '');
        if (stripos($header, 'Basic ') === 0) {
            $decoded = base64_decode(substr($header, 6), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);

                return [$id, $secret];
            }
        }

        return [
            (string) $this->request->input('client_id', ''),
            (string) $this->request->input('client_secret', ''),
        ];
    }

    /**
     * @param array<string, mixed> $params nulls are dropped
     */
    private function appendQuery(string $url, array $params): string
    {
        $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
        if ($params === []) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
