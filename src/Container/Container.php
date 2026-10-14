<?php

declare(strict_types=1);

namespace bapersbw\Container;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * A tiny service container with constructor autowiring. Enough to build
 * webservices and their dependencies; bind closures for anything that needs
 * explicit construction.
 */
final class Container
{
    /** @var array<string, Closure(Container): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @param Closure(Container): mixed $factory */
    public function bind(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function instance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }

        return $this->make($id);
    }

    /**
     * Instantiate a class, resolving constructor dependencies by type.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function make(string $class): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Cannot resolve [$class]: class does not exist.");
        }

        $constructor = (new ReflectionClass($class))->getConstructor();
        if ($constructor === null) {
            /** @var T */
            return new $class();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new RuntimeException("Cannot resolve parameter \${$parameter->getName()} of [$class].");
            }
        }

        /** @var T */
        return new $class(...$arguments);
    }
}
