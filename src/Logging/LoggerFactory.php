<?php

declare(strict_types=1);

namespace bapersbw\Logging;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;

/**
 * Builds the application's PSR-3 loggers (Monolog) from config.
 *
 * Each channel ("application", "security", …) writes to its own daily-rotated
 * file in the configured directory, kept for `max_files` days:
 *
 *   storage/logs/application-2026-10-16.log   request lifecycle
 *   storage/logs/security-2026-10-16.log      logins, auth failures, denials
 *   storage/logs/error-2026-10-16.log         every ERROR, from any channel
 *
 * The shared error log is a second handler on every channel, so an error shows
 * up both in its channel's file and in the consolidated error log.
 *
 * If `path` is set (e.g. 'php://stdout' for the console) a single stream handler
 * is used instead and no files are written.
 *
 * Because the rest of the framework depends only on {@see LoggerInterface}, you
 * can enrich this stack (syslog, JSON, Sentry, …) without touching anything else.
 */
final class LoggerFactory
{
    /**
     * @param array<string, mixed> $config the `logging` config section
     */
    public static function create(array $config = [], string $channel = 'application'): LoggerInterface
    {
        $level = Logger::toMonologLevel($config['level'] ?? 'debug');

        $logger = new Logger($channel);
        // Interpolate PSR-3 {placeholders} from context (and drop the used keys).
        $logger->pushProcessor(new PsrLogMessageProcessor(null, true));

        // Explicit stream (e.g. 'php://stdout' from the console) → one handler.
        $path = (string) ($config['path'] ?? '');
        if ($path !== '') {
            $logger->pushHandler(new StreamHandler($path, $level));

            return $logger;
        }

        $dir = rtrim((string) ($config['dir'] ?? (dirname(__DIR__, 2) . '/storage/logs')), '/\\');
        $maxFiles = (int) ($config['max_files'] ?? 14);

        // This channel's own daily file (everything at or above $level).
        $logger->pushHandler(new RotatingFileHandler($dir . '/' . $channel . '.log', $maxFiles, $level));

        // Every ERROR (from any channel) is also copied to the shared error log.
        $logger->pushHandler(new RotatingFileHandler($dir . '/error.log', $maxFiles, Level::Error));

        return $logger;
    }
}
