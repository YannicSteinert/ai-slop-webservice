<?php

declare(strict_types=1);

namespace bapersbw\Http;

/**
 * An immutable-ish value object describing the incoming HTTP request.
 *
 * `attributes` is a free-form bag used to carry request-scoped state between
 * layers (the matched route, the authenticated user, etc.). Middleware and the
 * kernel populate it; handlers and policies read from it.
 */
final class Request
{
    /**
     * @param string                $method     Upper-cased HTTP verb.
     * @param string                $path       Decoded path without query string, e.g. "/users/5/show".
     * @param array<string, string> $query      Query-string parameters.
     * @param array<string, mixed>  $body        Parsed request body (form or JSON).
     * @param array<string, string> $headers    Normalised (lower-cased) headers.
     * @param array<string, mixed>  $attributes Request-scoped state bag.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        private array $attributes = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';

        return new self(
            method: $method,
            path: $path,
            query: $_GET,
            body: self::parseBody(),
            headers: self::parseHeaders(),
        );
    }

    /** @return array<string, mixed> */
    private static function parseBody(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    /** @return array<string, string> */
    private static function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return $headers;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * Returns a copy of the request carrying an additional attribute, keeping
     * the request itself effectively immutable for callers upstream.
     */
    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;

        return $clone;
    }
}
