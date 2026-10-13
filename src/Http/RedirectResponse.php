<?php

declare(strict_types=1);

namespace bapersbw\Http;

final class RedirectResponse extends Response
{
    /** @param array<string, string> $headers */
    public function __construct(string $location, int $status = 302, array $headers = [])
    {
        $headers['Location'] = $location;

        parent::__construct(body: '', status: $status, headers: $headers);
    }
}
