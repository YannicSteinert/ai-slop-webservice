<?php

declare(strict_types=1);

/**
 * Minimal, dependency-free test harness. `test('name', fn)` registers a case;
 * tests/run.php executes them and reports. Assertions throw on failure, so the
 * TestResponse fluent assertions work here unchanged.
 */

use bapersbw\Testing\TestClient;

/** @var list<array{name: string, fn: callable}> */
$GLOBALS['__tests'] = $GLOBALS['__tests'] ?? [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = ['name' => $name, 'fn' => $fn];
}

function client(?array $config = null, ?array $modules = null): TestClient
{
    return TestClient::create($config, $modules);
}

function assert_true(bool $condition, string $message = 'Expected true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? $message . ' — ' : '') .
            'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true),
        );
    }
}

function assert_throws(string $exceptionClass, callable $fn): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!$e instanceof $exceptionClass) {
            throw new RuntimeException("Expected {$exceptionClass}, got " . $e::class . ': ' . $e->getMessage());
        }

        return;
    }

    throw new RuntimeException("Expected {$exceptionClass} to be thrown, but nothing was.");
}
