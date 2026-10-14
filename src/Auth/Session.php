<?php

declare(strict_types=1);

namespace bapersbw\Auth;

/**
 * Session store. In "native" mode it is backed by PHP's session (and sends the
 * cookie on {@see start()}); otherwise it is a plain in-memory array, which is
 * what the test client uses for isolation.
 */
final class Session
{
    /** @var array<string, mixed> */
    private array $data = [];

    private bool $started = false;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly bool $native = true,
        private readonly array $config = [],
    ) {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if ($this->native && session_status() !== PHP_SESSION_ACTIVE) {
            if (isset($this->config['name'])) {
                session_name((string) $this->config['name']);
            }
            session_set_cookie_params([
                'lifetime' => (int) ($this->config['lifetime'] ?? 0),
                'path' => (string) ($this->config['path'] ?? '/'),
                'secure' => (bool) ($this->config['secure'] ?? false),
                'httponly' => (bool) ($this->config['http_only'] ?? true),
                'samesite' => (string) ($this->config['same_site'] ?? 'Lax'),
            ]);
            session_start();
            $this->data = &$_SESSION;
        }

        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    /** Rotate the session id, keeping data (defends against fixation). */
    public function regenerate(): void
    {
        if ($this->native && $this->started) {
            session_regenerate_id(true);
        }
    }

    public function invalidate(): void
    {
        $this->data = [];
        $this->regenerate();
    }

    public function save(): void
    {
        if ($this->native && $this->started) {
            session_write_close();
            $this->started = false;
        }
    }
}
