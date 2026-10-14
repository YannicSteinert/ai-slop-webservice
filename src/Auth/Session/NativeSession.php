<?php

declare(strict_types=1);

namespace bapersbw\Auth\Session;

/**
 * PHP-native session storage. Cookie and lifetime behaviour come from config
 * (see config/config.php → 'session'). Starting it sends the session cookie,
 * so {@see SessionMiddleware} starts it once at the beginning of the request.
 */
final class NativeSession implements SessionInterface
{
    private bool $started = false;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config = [],
    ) {
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;

            return;
        }

        if (isset($this->config['name'])) {
            session_name((string) $this->config['name']);
        }

        session_set_cookie_params([
            'lifetime' => (int) ($this->config['lifetime'] ?? 0),
            'path' => (string) ($this->config['path'] ?? '/'),
            'domain' => (string) ($this->config['domain'] ?? ''),
            'secure' => (bool) ($this->config['secure'] ?? false),
            'httponly' => (bool) ($this->config['http_only'] ?? true),
            'samesite' => (string) ($this->config['same_site'] ?? 'Lax'),
        ]);

        session_start();
        $this->started = true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function all(): array
    {
        return $_SESSION ?? [];
    }

    public function regenerate(): void
    {
        if ($this->started) {
            session_regenerate_id(true);
        }
    }

    public function invalidate(): void
    {
        $_SESSION = [];
        if ($this->started) {
            session_regenerate_id(true);
        }
    }

    public function id(): string
    {
        return session_id() ?: '';
    }

    public function save(): void
    {
        if ($this->started) {
            session_write_close();
            $this->started = false;
        }
    }
}
