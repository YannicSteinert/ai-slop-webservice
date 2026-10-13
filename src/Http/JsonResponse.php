<?php

declare(strict_types=1);

namespace bapersbw\Http;

final class JsonResponse extends Response
{
    /** @param array<string, string> $headers */
    public function __construct(mixed $data = null, int $status = 200, array $headers = [])
    {
        $headers['Content-Type'] = 'application/json';

        parent::__construct(
            body: json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            status: $status,
            headers: $headers,
        );
    }
}
