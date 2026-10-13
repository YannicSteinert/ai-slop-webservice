<?php

declare(strict_types=1);

namespace bapersbw\Policy;

use bapersbw\Http\Request;
use bapersbw\Http\Response;
use bapersbw\Middleware\MiddlewareInterface;
use bapersbw\Routing\RouteMatch;

/**
 * Enforces the #[Authorize] policies declared on the matched route.
 *
 * It reads the {@see RouteMatch} the kernel placed on the request, then asks
 * the {@see Gate} to authorize each policy in turn. A route with no policies
 * passes straight through, so this middleware is safe to keep installed even
 * before any policies exist.
 */
final class AuthorizationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Gate $gate,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $match = $request->attribute('route');

        if ($match instanceof RouteMatch) {
            foreach ($match->route->policies as $authorize) {
                $this->gate->authorize($authorize->policy, $request, $authorize->arguments);
            }
        }

        return $next($request);
    }
}
