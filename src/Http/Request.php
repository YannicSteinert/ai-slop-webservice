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
     * @param string                $basePath   Mount path stripped from `path` (for building URLs back).
     * @param array<string, mixed>  $attributes Request-scoped state bag.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly string $basePath = '',
        private array $attributes = [],
    ) {
    }

    public static function fromGlobals(string $basePath = ''): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';

        $base = self::resolveBase($basePath);

        return new self(
            method: $method,
            path: self::stripBase($path, $base),
            query: $_GET,
            body: self::parseBody(),
            headers: self::parseHeaders(),
            basePath: $base,
        );
    }

    /**
     * Prefix an app-absolute path (e.g. "/login") with the base path, so a
     * redirect/link points back at this mount rather than the server root.
     */
    public function url(string $path): string
    {
        return $this->basePath . $path;
    }

    /**
     * Resolve the application's base path, in order of precedence:
     *   1. the explicit $basePath (config 'base_path'), e.g. "/foo/ws/public";
     *   2. the BASE_PATH environment variable;
     *   3. the front controller's directory from SCRIPT_NAME (auto-detect).
     *
     * Set config 'base_path' when the server can't be configured — it also
     * covers running without URL rewriting by pointing at the script itself,
     * e.g. "/foo/ws/public/index.php".
     */
    private static function resolveBase(string $basePath): string
    {
        $base = $basePath !== '' ? $basePath : (string) (getenv('BASE_PATH') ?: '');
        if ($base === '') {
            $base = dirname(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        }
        $base = rtrim(str_replace('\\', '/', $base), '/');

        return $base === '.' ? '' : $base;
    }

    /** Strip the resolved base so routing sees module-relative paths. */
    private static function stripBase(string $path, string $base): string
    {
        if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return $path === '' ? '/' : $path;
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
