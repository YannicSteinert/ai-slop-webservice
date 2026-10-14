<?php

declare(strict_types=1);

namespace bapersbw\Auth\Session;

use bapersbw\Http\Request;
use bapersbw\Http\Response;
use bapersbw\Middleware\MiddlewareInterface;

/**
 * Starts the session at the beginning of the request and persists it at the
 * end. It runs first in the pipeline so guards and the login flow can rely on
 * an active session.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionInterface $session,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $this->session->start();

        try {
            return $next($request->withAttribute('session', $this->session));
        } finally {
            $this->session->save();
        }
    }
}
