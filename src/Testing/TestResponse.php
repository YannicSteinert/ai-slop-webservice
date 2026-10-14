<?php

declare(strict_types=1);

namespace bapersbw\Testing;

use bapersbw\Http\Response;
use RuntimeException;

/**
 * Wraps a {@see Response} with fluent assertions for tests. Assertion failures
 * throw, so they work with any runner (including the bundled tests/run.php).
 */
final class TestResponse
{
    public function __construct(
        public readonly Response $response,
    ) {
    }

    public function status(): int
    {
        return $this->response->getStatus();
    }

    public function body(): string
    {
        return $this->response->getBody();
    }

    public function header(string $name): ?string
    {
        return $this->response->getHeaders()[$name] ?? null;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body(), true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Response body is not a JSON object: ' . $this->body());
        }

        return $decoded;
    }

    public function assertStatus(int $expected): self
    {
        if ($this->status() !== $expected) {
            throw new RuntimeException(
                "Expected status {$expected}, got {$this->status()}. Body: {$this->body()}",
            );
        }

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertHeader(string $name, ?string $expected = null): self
    {
        $actual = $this->header($name);
        if ($actual === null) {
            throw new RuntimeException("Expected header [{$name}] to be present.");
        }
        if ($expected !== null && $actual !== $expected) {
            throw new RuntimeException("Expected header [{$name}] = [{$expected}], got [{$actual}].");
        }

        return $this;
    }

    /**
     * Assert the JSON body contains the given key/value pairs (subset match,
     * recursive for nested arrays).
     *
     * @param array<string, mixed> $expected
     */
    public function assertJsonContains(array $expected): self
    {
        $this->assertSubset($expected, $this->json(), '$');

        return $this;
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private function assertSubset(array $expected, array $actual, string $path): void
    {
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual)) {
                throw new RuntimeException("Missing key [{$path}.{$key}] in JSON response.");
            }

            if (is_array($value) && is_array($actual[$key])) {
                $this->assertSubset($value, $actual[$key], "{$path}.{$key}");
                continue;
            }

            if ($actual[$key] !== $value) {
                throw new RuntimeException(sprintf(
                    'Expected [%s.%s] = %s, got %s.',
                    $path,
                    $key,
                    var_export($value, true),
                    var_export($actual[$key], true),
                ));
            }
        }
    }
}
