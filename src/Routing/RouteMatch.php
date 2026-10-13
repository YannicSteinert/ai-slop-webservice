<?php

declare(strict_types=1);

namespace bapersbw\Routing;

/**
 * The result of successfully routing a request: which route was hit, under
 * which module, and the parameter values extracted from the path.
 */
final class RouteMatch
{
    /** @param array<string, string> $parameters */
    public function __construct(
        public readonly string $module,
        public readonly RouteDefinition $route,
        public readonly array $parameters = [],
    ) {
    }
}
