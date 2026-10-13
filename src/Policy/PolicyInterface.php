<?php

declare(strict_types=1);

namespace bapersbw\Policy;

use bapersbw\Http\Request;

/**
 * A policy decides whether the current request is allowed to proceed.
 *
 * Implementations typically read the authenticated user (and the matched route
 * parameters) from the request. `$arguments` are the extra values passed in the
 * #[Authorize('name', ...args)] attribute, e.g. a required role.
 *
 * Nothing enforces policies until they are registered on the {@see Gate} and a
 * route is annotated with #[Authorize], so authorization can be added purely
 * additively, module by module.
 */
interface PolicyInterface
{
    /**
     * @param list<string|int|float|bool> $arguments
     */
    public function authorize(Request $request, array $arguments): bool;
}
