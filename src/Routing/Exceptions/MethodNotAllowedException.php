<?php

declare(strict_types=1);

namespace bapersbw\Routing\Exceptions;

use bapersbw\Exceptions\HttpException;

final class MethodNotAllowedException extends HttpException
{
    /** @param list<string> $allowedMethods */
    public function __construct(array $allowedMethods)
    {
        parent::__construct(
            405,
            'Method Not Allowed',
            ['Allow' => implode(', ', $allowedMethods)],
        );
    }
}
