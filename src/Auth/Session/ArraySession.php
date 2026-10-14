<?php

declare(strict_types=1);

namespace bapersbw\Auth\Session;

/**
 * In-memory session for tests and CLI. Data persists for the lifetime of the
 * instance, so a single {@see \bapersbw\Testing\TestClient} keeps a coherent
 * session across multiple requests.
 */
final class ArraySession implements SessionInterface
{
    /** @param array<string, mixed> $data */
    public function __construct(
        private array $data = [],
        private bool $started = false,
        private string $id = '',
    ) {
    }

    public function start(): void
    {
        $this->started = true;
        if ($this->id === '') {
            $this->id = bin2hex(random_bytes(16));
        }
    }

    public function isStarted(): bool
    {
        return $this->started;
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

    public function all(): array
    {
        return $this->data;
    }

    public function regenerate(): void
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function invalidate(): void
    {
        $this->data = [];
        $this->regenerate();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function save(): void
    {
    }
}
