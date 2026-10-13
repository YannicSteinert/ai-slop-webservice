<?php

declare(strict_types=1);

namespace bapersbw\Attributes;

use Attribute;

/**
 * Requires that one or more policies pass before a route handler runs.
 *
 * May be placed on a webservice class (applies to every route in it) and/or on
 * individual methods (applies to that route only); both are combined.
 *
 * Policies are resolved by name through the {@see \bapersbw\Policy\Gate}. The
 * enforcement itself lives in {@see \bapersbw\Policy\AuthorizationMiddleware},
 * so adding authorization later is purely additive: register a policy on the
 * Gate and annotate the routes that need it.
 *
 * Example:
 *   #[Authorize('users.view')]
 *   #[Authorize('users.edit', 'self')]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Authorize
{
    /** @var list<string|int|float|bool> */
    public readonly array $arguments;

    public function __construct(
        public readonly string $policy,
        string|int|float|bool ...$arguments,
    ) {
        $this->arguments = array_values($arguments);
    }
}
