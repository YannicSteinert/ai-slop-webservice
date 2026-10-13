<?php

declare(strict_types=1);

namespace bapersbw\Routing\Exceptions;

use bapersbw\Exceptions\HttpException;

final class RouteNotFoundException extends HttpException
{
    public function __construct(string $message = 'Not Found')
    {
        parent::__construct(404, $message);
    }
}
