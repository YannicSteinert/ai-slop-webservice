<?php

declare(strict_types=1);

namespace bapersbw\Modules\OAuth;

use bapersbw\Attributes\Route;
use bapersbw\Auth\TokenStore;
use bapersbw\Http\Response;
use bapersbw\Webservice;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Inward OAuth2 token endpoint, mounted under "oauth".
 *
 * Implements the client-credentials grant: a calling service presents its
 * client_id + client_secret and receives a short-lived bearer token, stored in
 * the database by the {@see TokenStore}. Other modules are then protected by
 * requiring that token (via the TokenGuard + an #[Authorize] policy).
 *
 *   POST /oauth/token   grant_type=client_credentials&client_id=…&client_secret=…
 */
final class OAuthWebservice extends Webservice
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly TokenStore $tokens,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    #[Route('POST', '/token')]
    public function token(): Response
    {
        if ((string) $this->request->input('grant_type') !== 'client_credentials') {
            return $this->json(['error' => 'unsupported_grant_type'], 400);
        }

        $clientId = (string) $this->request->input('client_id', '');
        $secret = (string) $this->request->input('client_secret', '');

        $token = $this->tokens->issue($clientId, $secret);
        if ($token === null) {
            $this->logger->warning('OAuth token denied for client {client}', [
                'request_id' => $this->request->attribute('request_id'),
                'client' => $clientId,
            ]);

            return $this->json(['error' => 'invalid_client'], 401)
                ->withHeader('WWW-Authenticate', 'Bearer');
        }

        $this->logger->info('OAuth token issued to client {client}', [
            'request_id' => $this->request->attribute('request_id'),
            'client' => $clientId,
            'expires_in' => $token['expires_in'],
        ]);

        return $this->json($token);
    }
}
