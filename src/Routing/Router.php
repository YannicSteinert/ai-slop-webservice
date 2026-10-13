<?php

declare(strict_types=1);

namespace bapersbw\Routing;

use bapersbw\Http\Request;
use bapersbw\Module\ModuleRegistry;
use bapersbw\Routing\Exceptions\MethodNotAllowedException;
use bapersbw\Routing\Exceptions\RouteNotFoundException;

/**
 * Resolves a request to a {@see RouteMatch} in two stages:
 *
 *   1. The first path segment selects the module (webservice class) via the
 *      {@see ModuleRegistry}.
 *   2. The remaining path is matched against that webservice's compiled
 *      attribute routes.
 *
 * HEAD is treated as GET for matching purposes.
 */
final class Router
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly AttributeRouteLoader $loader,
    ) {
    }

    public function match(Request $request): RouteMatch
    {
        [$module, $rest] = $this->split($request->path);

        if ($module === '') {
            throw new RouteNotFoundException('No module specified.');
        }

        $webserviceClass = $this->modules->resolve($module);
        if ($webserviceClass === null) {
            throw new RouteNotFoundException("Unknown module [$module].");
        }

        $method = $request->method === 'HEAD' ? 'GET' : $request->method;

        $allowedMethods = [];
        foreach ($this->loader->load($webserviceClass) as $route) {
            $parameters = $route->match($rest);
            if ($parameters === null) {
                continue;
            }

            if ($route->httpMethod !== $method) {
                $allowedMethods[$route->httpMethod] = true;
                continue;
            }

            return new RouteMatch($module, $route, $parameters);
        }

        if ($allowedMethods !== []) {
            throw new MethodNotAllowedException(array_keys($allowedMethods));
        }

        throw new RouteNotFoundException("No route for [{$request->method} {$request->path}].");
    }

    /**
     * Split a full path into the module segment and the module-relative
     * remainder (always starting with "/").
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $path): array
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return ['', '/'];
        }

        $slash = strpos($trimmed, '/');
        if ($slash === false) {
            return [$trimmed, '/'];
        }

        $module = substr($trimmed, 0, $slash);
        $rest = substr($trimmed, $slash);

        return [$module, $rest];
    }
}
