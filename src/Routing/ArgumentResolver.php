<?php

declare(strict_types=1);

namespace bapersbw\Routing;

use bapersbw\Container\ContainerInterface;
use bapersbw\Http\Request;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

/**
 * Binds a handler method's parameters, in order, from the available sources:
 *
 *   - a path parameter whose name matches the argument (cast to the declared
 *     scalar type where possible);
 *   - the current {@see Request}, by type hint;
 *   - any other class dependency, resolved from the container;
 *   - the argument's default value, when nothing else fits.
 *
 * This is what lets a route like "/{user_id}/show" map onto
 * `show(int $user_id)` with no manual wiring.
 */
final class ArgumentResolver
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * @param array<string, string> $routeParameters
     * @return list<mixed>
     */
    public function resolve(ReflectionMethod $method, array $routeParameters, Request $request): array
    {
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if (array_key_exists($name, $routeParameters)) {
                $arguments[] = $this->cast($routeParameters[$name], $type);
                continue;
            }

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $className = $type->getName();
                if ($className === Request::class) {
                    $arguments[] = $request;
                    continue;
                }
                $arguments[] = $this->container->get($className);
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
                $arguments[] = null;
                continue;
            }

            throw new RuntimeException(
                "Cannot resolve argument \${$name} for " .
                "{$method->getDeclaringClass()->getName()}::{$method->getName()}().",
            );
        }

        return $arguments;
    }

    private function cast(string $value, ?ReflectionNamedType $type): mixed
    {
        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin()) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }
}
