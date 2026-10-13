<?php

declare(strict_types=1);

namespace bapersbw\Http;

/**
 * Base HTTP response. Specialised responses (JSON, redirect) extend this.
 * A response is a plain value object; the kernel calls {@see send()} once.
 */
class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        protected string $body = '',
        protected int $status = 200,
        protected array $headers = [],
    ) {
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function withStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function withHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        echo $this->body;
    }
}
