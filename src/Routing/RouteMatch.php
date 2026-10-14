<?php

declare(strict_types=1);

namespace bapersbw\Routing;

use bapersbw\Attributes\Authorize;

/**
 * The result of routing a request: the handler to invoke, the path parameters
 * extracted for it, and the policies guarding it.
 */
final class RouteMatch
{
    /**
     * @param class-string          $webserviceClass
     * @param array<string, string> $parameters
     * @param list<Authorize>       $policies
     */
    public function __construct(
        public readonly string $webserviceClass,
        public readonly string $handlerMethod,
        public readonly array $parameters,
        public readonly array $policies,
    ) {
    }
}
