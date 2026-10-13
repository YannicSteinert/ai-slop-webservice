<?php

declare(strict_types=1);

namespace bapersbw\Http;

/**
 * Supported HTTP methods. Kept as a backed enum so routes and the request
 * layer can share a single source of truth while still accepting raw strings.
 */
enum HttpMethod: string
{
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';
    case HEAD = 'HEAD';
    case OPTIONS = 'OPTIONS';

    public static function fromString(string $method): self
    {
        return self::from(strtoupper($method));
    }
}
