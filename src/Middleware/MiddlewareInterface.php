<?php

declare(strict_types=1);

namespace bapersbw\Middleware;

use bapersbw\Http\Request;
use bapersbw\Http\Response;

/**
 * A middleware wraps the handling of a request. Call `$next($request)` to pass
 * control down the pipeline (optionally with a modified request), or return a
 * Response directly to short-circuit it.
 *
 * This is the primary extension seam: authentication, policy enforcement,
 * logging, CORS, rate limiting, etc. all slot in here without touching the
 * router or the webservices.
 */
interface MiddlewareInterface
{
    /**
     * @param callable(Request): Response $next
     */
    public function process(Request $request, callable $next): Response;
}
