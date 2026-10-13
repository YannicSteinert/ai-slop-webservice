<?php

declare(strict_types=1);

namespace bapersbw\Policy;

use bapersbw\Exceptions\HttpException;

/**
 * Thrown when a policy denies access. Maps to 403 Forbidden.
 */
final class AuthorizationException extends HttpException
{
    public function __construct(string $message = 'Forbidden')
    {
        parent::__construct(403, $message);
    }
}
