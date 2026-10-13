<?php

declare(strict_types=1);

namespace bapersbw\Container;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * A small, dependency-free service container with constructor autowiring.
 *
 * It is intentionally minimal: enough to instantiate webservices, middleware
 * and policies and wire their dependencies. Swap it for a PSR-11 container
 * later without touching the rest of the framework — the kernel only depends
 * on {@see ContainerInterface}.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, Closure(ContainerInterface): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, bool> */
    private array $shared = [];

    /**
     * Bind an id to a factory.
     *
     * @param Closure(ContainerInterface): mixed $factory
     */
    public function bind(string $id, Closure $factory, bool $shared = false): void
    {
        $this->factories[$id] = $factory;
        $this->shared[$id] = $shared;
        unset($this->instances[$id]);
    }

    /** @param Closure(ContainerInterface): mixed $factory */
    public function singleton(string $id, Closure $factory): void
    {
        $this->bind($id, $factory, true);
    }

    public function instance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
        $this->shared[$id] = true;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id])
            || isset($this->instances[$id])
            || class_exists($id);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->factories[$id])) {
            $object = ($this->factories[$id])($this);
        } else {
            $object = $this->make($id);
        }

        if ($this->shared[$id] ?? false) {
            $this->instances[$id] = $object;
        }

        return $object;
    }

    /**
     * Instantiate a concrete class, resolving its constructor dependencies.
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

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Cannot instantiate [$class].");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            /** @var T */
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new RuntimeException(
                "Cannot resolve parameter \${$parameter->getName()} of [$class].",
            );
        }

        /** @var T */
        return $reflection->newInstanceArgs($arguments);
    }
}
