<?php

declare(strict_types=1);

namespace bapersbw\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An exception that maps directly onto an HTTP status code. The kernel catches
 * these and renders a clean error response instead of a stack trace.
 */
class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        private readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
