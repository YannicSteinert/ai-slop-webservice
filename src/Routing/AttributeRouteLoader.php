<?php

declare(strict_types=1);

namespace bapersbw\Routing;

use bapersbw\Attributes\Authorize;
use bapersbw\Attributes\Route;
use bapersbw\Webservice\Webservice;
use ReflectionClass;

/**
 * Reads #[Route] (and #[Authorize]) attributes off a webservice class and
 * compiles them into {@see RouteDefinition} objects.
 *
 * Compilation turns a path template such as "/{user_id}/show" into a named,
 * anchored regex. A parameter may carry a constraint after a colon,
 * e.g. "/{id:\d+}". Multiple parameters per route are fully supported.
 *
 * Results are cached per class for the lifetime of the loader so reflection
 * runs at most once per webservice.
 */
final class AttributeRouteLoader
{
    /** @var array<class-string<Webservice>, list<RouteDefinition>> */
    private array $cache = [];

    /**
     * @param class-string<Webservice> $webserviceClass
     * @return list<RouteDefinition>
     */
    public function load(string $webserviceClass): array
    {
        if (isset($this->cache[$webserviceClass])) {
            return $this->cache[$webserviceClass];
        }

        $reflection = new ReflectionClass($webserviceClass);

        $classPolicies = $this->readPolicies($reflection->getAttributes(Authorize::class));

        $routes = [];
        foreach ($reflection->getMethods() as $method) {
            $routeAttributes = $method->getAttributes(Route::class);
            if ($routeAttributes === []) {
                continue;
            }

            $methodPolicies = $this->readPolicies($method->getAttributes(Authorize::class));
            $policies = array_merge($classPolicies, $methodPolicies);

            foreach ($routeAttributes as $attribute) {
                /** @var Route $route */
                $route = $attribute->newInstance();
                [$regex, $parameterNames] = $this->compile($route->path);

                $routes[] = new RouteDefinition(
                    httpMethod: $route->method,
                    pathTemplate: $route->path,
                    regex: $regex,
                    parameterNames: $parameterNames,
                    webserviceClass: $webserviceClass,
                    handlerMethod: $method->getName(),
                    policies: $policies,
                    name: $route->name,
                );
            }
        }

        return $this->cache[$webserviceClass] = $routes;
    }

    /**
     * Compile a path template into an anchored regex plus the ordered list of
     * parameter names it contains.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function compile(string $template): array
    {
        $template = '/' . trim($template, '/');

        $parameterNames = [];
        $parts = preg_split(
            '/(\{[a-zA-Z_][a-zA-Z0-9_]*(?::[^{}]+)?\})/',
            $template,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        );

        $pattern = '';
        foreach ($parts ?: [] as $part) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^{}]+))?\}$/', $part, $m) === 1) {
                $parameterNames[] = $m[1];
                $constraint = $m[2] ?? '[^/]+';
                $pattern .= '(?P<' . $m[1] . '>' . $constraint . ')';
            } else {
                $pattern .= preg_quote($part, '#');
            }
        }

        return ['#^' . $pattern . '$#', $parameterNames];
    }

    /**
     * @param array<int, \ReflectionAttribute<Authorize>> $attributes
     * @return list<Authorize>
     */
    private function readPolicies(array $attributes): array
    {
        return array_map(static fn ($attribute) => $attribute->newInstance(), $attributes);
    }
}
