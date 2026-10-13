<?php

declare(strict_types=1);

namespace bapersbw\Policy;

use bapersbw\Container\ContainerInterface;
use bapersbw\Http\Request;
use RuntimeException;

/**
 * The registry and entry point for policies.
 *
 * Policies are registered by name against either a ready instance or a
 * class-string (resolved lazily through the container). The Gate is consulted
 * by {@see AuthorizationMiddleware} for every #[Authorize] on a matched route.
 */
final class Gate
{
    /** @var array<string, PolicyInterface|class-string<PolicyInterface>> */
    private array $policies = [];

    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }

    /** @param PolicyInterface|class-string<PolicyInterface> $policy */
    public function register(string $name, PolicyInterface|string $policy): void
    {
        $this->policies[$name] = $policy;
    }

    public function has(string $name): bool
    {
        return isset($this->policies[$name]);
    }

    /**
     * @param list<string|int|float|bool> $arguments
     */
    public function allows(string $name, Request $request, array $arguments = []): bool
    {
        return $this->resolve($name)->authorize($request, $arguments);
    }

    /**
     * Enforce a policy, throwing {@see AuthorizationException} when it denies.
     *
     * @param list<string|int|float|bool> $arguments
     */
    public function authorize(string $name, Request $request, array $arguments = []): void
    {
        if (!$this->allows($name, $request, $arguments)) {
            throw new AuthorizationException("Denied by policy [$name].");
        }
    }

    private function resolve(string $name): PolicyInterface
    {
        if (!isset($this->policies[$name])) {
            // Fail closed: a route asked for a policy that was never registered.
            throw new RuntimeException("No policy registered for [$name].");
        }

        $policy = $this->policies[$name];
        if (is_string($policy)) {
            $policy = $this->container->get($policy);
            $this->policies[$name] = $policy;
        }

        return $policy;
    }
}
