<?php

declare(strict_types=1);

namespace bapersbw\Policy;

use bapersbw\Auth\Identity;
use bapersbw\Http\Request;
use bapersbw\Http\Response;
use bapersbw\Middleware\MiddlewareInterface;
use bapersbw\Routing\RouteMatch;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly Gate $gate,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function process(Request $request, callable $next): Response
    {
        $match = $request->attribute('route');

        if ($match instanceof RouteMatch) {
            foreach ($match->route->policies as $authorize) {
                if (!$this->gate->allows($authorize->policy, $request, $authorize->arguments)) {
                    $this->logDenial($request, $authorize->policy);
                    throw new AuthorizationException("Denied by policy [{$authorize->policy}].");
                }
            }
        }

        return $next($request);
    }

    private function logDenial(Request $request, string $policy): void
    {
        $identity = $request->attribute('identity');

        $this->logger->warning('Authorization denied by policy {policy}', [
            'request_id' => $request->attribute('request_id'),
            'policy' => $policy,
            'path' => $request->path,
            'subject' => $identity instanceof Identity ? $identity->id : null,
        ]);
    }
}
