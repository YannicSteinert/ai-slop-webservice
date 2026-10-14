<?php

declare(strict_types=1);

/**
 * Tiny test runner. Usage:  php tests/run.php
 *
 * Loads every *_test.php file in this directory, runs the registered tests and
 * exits non-zero if any fail (so it slots into CI without extra tooling).
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support.php';

foreach (glob(__DIR__ . '/*_test.php') ?: [] as $file) {
    require $file;
}

$passed = 0;
$failed = 0;

foreach ($GLOBALS['__tests'] as $case) {
    try {
        ($case['fn'])();
        $passed++;
        fwrite(STDOUT, "  \033[32mPASS\033[0m {$case['name']}\n");
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDOUT, "  \033[31mFAIL\033[0m {$case['name']}\n");
        fwrite(STDOUT, "       {$e->getMessage()}\n");
    }
}

$total = $passed + $failed;
fwrite(STDOUT, "\n{$passed}/{$total} passed" . ($failed > 0 ? ", {$failed} failed" : '') . "\n");

exit($failed > 0 ? 1 : 0);
