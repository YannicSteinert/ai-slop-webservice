<?php

declare(strict_types=1);

namespace bapersbw\Auth\Exceptions;

use bapersbw\Exceptions\HttpException;

/**
 * Thrown when authentication fails or is required but absent. Maps to 401.
 */
final class AuthenticationException extends HttpException
{
    public function __construct(string $message = 'Unauthenticated')
    {
        parent::__construct(401, $message);
    }
}
