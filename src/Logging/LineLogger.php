<?php

declare(strict_types=1);

namespace bapersbw\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * A small PSR-3 logger that writes one formatted line per record to a stream or
 * file. It is the default logger; bind any other PSR-3 logger (Monolog, etc.)
 * on LoggerInterface to replace it without touching the framework.
 *
 * Format:  [2026-10-14T12:00:00+00:00] INFO: message {"context":"as-json"}
 *
 * Messages support PSR-3 `{placeholder}` interpolation from the context.
 */
final class LineLogger extends AbstractLogger
{
    private const WEIGHTS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /** @var resource */
    private $handle;

    private readonly int $threshold;

    public function __construct(string $path = 'php://stderr', string $minLevel = LogLevel::DEBUG)
    {
        $this->threshold = self::WEIGHTS[$minLevel] ?? 0;
        $this->handle = $this->open($path);
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ((self::WEIGHTS[$level] ?? 0) < $this->threshold) {
            return;
        }

        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('c'),
            strtoupper((string) $level),
            $this->interpolate((string) $message, $context),
            $context !== [] ? ' ' . $this->encodeContext($context) : '',
        );

        fwrite($this->handle, $line);
    }

    /** @return resource */
    private function open(string $path)
    {
        if (!str_starts_with($path, 'php://')) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $handle = @fopen($path, 'a');
        } else {
            $handle = @fopen($path, 'w');
        }

        // Never let logging break the request; fall back to stderr, then a sink.
        return $handle ?: (@fopen('php://stderr', 'w') ?: fopen('php://memory', 'w'));
    }

    /** @param array<string, mixed> $context */
    private function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable || $value === null) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }

    /** @param array<string, mixed> $context */
    private function encodeContext(array $context): string
    {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded !== false ? $encoded : '{}';
    }
}
