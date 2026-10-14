<?php

declare(strict_types=1);

namespace bapersbw\Routing;

use bapersbw\Attributes\Authorize;
use bapersbw\Attributes\Route;
use bapersbw\Exceptions\HttpException;
use bapersbw\Http\Request;
use bapersbw\Webservice;
use ReflectionClass;

/**
 * Resolves a request in two stages: the first path segment selects the module
 * (webservice class) from the module map; the remaining path is matched against
 * that class's #[Route] attributes.
 *
 * It also loads the attribute routes (reflection, cached per class) and
 * compiles path templates like "/{user_id}/show" — including constraints such
 * as "/{id:\d+}" and multiple parameters — into named regexes.
 */
final class Router
{
    /** @var array<string, class-string<Webservice>> */
    private array $modules;

    /** @var array<class-string, list<array{method: string, regex: string, names: list<string>, handler: string, policies: list<Authorize>}>> */
    private array $cache = [];

    /** @param array<string, class-string<Webservice>> $modules */
    public function __construct(array $modules)
    {
        $this->modules = array_change_key_case($modules);
    }

    public function match(Request $request): RouteMatch
    {
        [$module, $rest] = $this->split($request->path);

        $class = $this->modules[strtolower($module)] ?? null;
        if ($class === null) {
            throw new HttpException(404, $module === '' ? 'No module specified.' : "Unknown module [$module].");
        }

        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $allowed = [];

        foreach ($this->routesFor($class) as $route) {
            if (preg_match($route['regex'], $rest, $m) !== 1) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[$route['method']] = true;
                continue;
            }

            $parameters = [];
            foreach ($route['names'] as $name) {
                $parameters[$name] = $m[$name];
            }

            return new RouteMatch($class, $route['handler'], $parameters, $route['policies']);
        }

        if ($allowed !== []) {
            throw new HttpException(405, 'Method Not Allowed', ['Allow' => implode(', ', array_keys($allowed))]);
        }

        throw new HttpException(404, "No route for [{$request->method} {$request->path}].");
    }

    /**
     * @param class-string $class
     * @return list<array{method: string, regex: string, names: list<string>, handler: string, policies: list<Authorize>}>
     */
    private function routesFor(string $class): array
    {
        if (isset($this->cache[$class])) {
            return $this->cache[$class];
        }

        $reflection = new ReflectionClass($class);
        $classPolicies = $this->policies($reflection->getAttributes(Authorize::class));

        $routes = [];
        foreach ($reflection->getMethods() as $method) {
            $policies = array_merge($classPolicies, $this->policies($method->getAttributes(Authorize::class)));

            foreach ($method->getAttributes(Route::class) as $attribute) {
                /** @var Route $route */
                $route = $attribute->newInstance();
                [$regex, $names] = $this->compile($route->path);

                $routes[] = [
                    'method' => $route->method,
                    'regex' => $regex,
                    'names' => $names,
                    'handler' => $method->getName(),
                    'policies' => $policies,
                ];
            }
        }

        return $this->cache[$class] = $routes;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function compile(string $template): array
    {
        $template = '/' . trim($template, '/');
        $names = [];
        $pattern = '';

        $parts = preg_split(
            '/(\{[a-zA-Z_][a-zA-Z0-9_]*(?::[^{}]+)?\})/',
            $template,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        ) ?: [];

        foreach ($parts as $part) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^{}]+))?\}$/', $part, $m) === 1) {
                $names[] = $m[1];
                $pattern .= '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')';
            } else {
                $pattern .= preg_quote($part, '#');
            }
        }

        return ['#^' . $pattern . '$#', $names];
    }

    /**
     * @param array<int, \ReflectionAttribute<Authorize>> $attributes
     * @return list<Authorize>
     */
    private function policies(array $attributes): array
    {
        return array_map(static fn ($a) => $a->newInstance(), $attributes);
    }

    /** @return array{0: string, 1: string} */
    private function split(string $path): array
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return ['', '/'];
        }

        $slash = strpos($trimmed, '/');

        return $slash === false
            ? [$trimmed, '/']
            : [substr($trimmed, 0, $slash), substr($trimmed, $slash)];
    }
}
