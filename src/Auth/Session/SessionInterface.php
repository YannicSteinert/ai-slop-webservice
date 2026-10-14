<?php

declare(strict_types=1);

namespace bapersbw\Auth\Session;

/**
 * Abstraction over session storage so the auth layer never touches $_SESSION
 * directly. {@see NativeSession} backs it with PHP sessions in production;
 * {@see ArraySession} backs it in tests.
 */
interface SessionInterface
{
    public function start(): void;

    public function isStarted(): bool;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function remove(string $key): void;

    /** @return array<string, mixed> */
    public function all(): array;

    /** Issue a new session id, keeping the data (session-fixation defence). */
    public function regenerate(): void;

    /** Drop all data and start a fresh session. */
    public function invalidate(): void;

    public function id(): string;

    /** Persist the session (write + close for native sessions). */
    public function save(): void;
}
