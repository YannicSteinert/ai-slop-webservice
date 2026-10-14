<?php

declare(strict_types=1);

namespace bapersbw\Attributes;

use Attribute;

/**
 * Declares a route on a webservice method.
 *
 * The path is relative to the module that the webservice is mounted under
 * (see config/modules.php). Path parameters use the `{name}` syntax and may
 * carry an optional regex constraint: `{id:\d+}`.
 *
 * Multiple routes may point at the same method by repeating the attribute.
 *
 * Example:
 *   #[Route('GET', '/{user_id}/show')]
 *   #[Route('GET', '/{group_id}/users/{user_id}')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Route
{
    /** @var string Upper-cased HTTP method. */
    public readonly string $method;

    /**
     * @param string $method HTTP verb (GET, POST, ...).
     * @param string $path   Module-relative path, e.g. "/{user_id}/show".
     * @param string|null $name Optional unique route name for reverse lookups.
     */
    public function __construct(
        string $method,
        public readonly string $path,
        public readonly ?string $name = null,
    ) {
        $this->method = strtoupper($method);
    }
}
