<?php

declare(strict_types=1);

namespace bapersbw\Policy;

use bapersbw\Exceptions\HttpException;
use bapersbw\Http\Request;

/**
 * Registry and entry point for policies. Register a policy by name, then guard
 * routes with #[Authorize('name')]; the kernel enforces them via {@see allows()}.
 */
final class Gate
{
    /** @var array<string, PolicyInterface> */
    private array $policies = [];

    public function register(string $name, PolicyInterface $policy): void
    {
        $this->policies[$name] = $policy;
    }

    /** @param list<string|int|float|bool> $arguments */
    public function allows(string $name, Request $request, array $arguments = []): bool
    {
        if (!isset($this->policies[$name])) {
            // Fail closed: a route asked for a policy that was never registered.
            throw new HttpException(500, "No policy registered for [$name].");
        }

        return $this->policies[$name]->authorize($request, $arguments);
    }
}
