<?php

declare(strict_types=1);

namespace bapersbw\Routing;

use bapersbw\Attributes\Authorize;
use bapersbw\Webservice\Webservice;

/**
 * A single compiled route: the HTTP method, the regex derived from the path
 * template, the names of its parameters, the webservice method that handles it
 * and any policies that guard it.
 *
 * Instances are produced by {@see AttributeRouteLoader} and are immutable.
 */
final class RouteDefinition
{
    /**
     * @param class-string<Webservice> $webserviceClass
     * @param string                   $handlerMethod   Method name on the webservice.
     * @param string                   $regex           Compiled, anchored pattern.
     * @param list<string>             $parameterNames  Ordered path parameter names.
     * @param list<Authorize>          $policies        Combined class + method policies.
     */
    public function __construct(
        public readonly string $httpMethod,
        public readonly string $pathTemplate,
        public readonly string $regex,
        public readonly array $parameterNames,
        public readonly string $webserviceClass,
        public readonly string $handlerMethod,
        public readonly array $policies = [],
        public readonly ?string $name = null,
    ) {
    }

    /**
     * Attempt to match a module-relative path. Returns the extracted parameters
     * (name => value) on success, or null if the path does not match.
     *
     * @return array<string, string>|null
     */
    public function match(string $path): ?array
    {
        if (preg_match($this->regex, $path, $matches) !== 1) {
            return null;
        }

        $parameters = [];
        foreach ($this->parameterNames as $name) {
            if (isset($matches[$name])) {
                $parameters[$name] = $matches[$name];
            }
        }

        return $parameters;
    }
}
