<?php

declare(strict_types=1);

namespace bapersbw\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;

/**
 * Builds the application's PSR-3 logger (Monolog) from config.
 *
 * One line per record is written to the configured file or stream at or above
 * the configured level; the `{placeholder}` context and request_id used by the
 * framework are included by Monolog's default line formatter.
 *
 * Because the rest of the framework depends only on {@see LoggerInterface}, you
 * can build a richer Monolog stack here (rotating files, syslog, JSON, Sentry,
 * …) or bind an entirely different PSR-3 logger without touching anything else.
 */
final class LoggerFactory
{
    /**
     * @param array<string, mixed> $config the `logging` config section
     */
    public static function create(array $config = [], string $channel = 'app'): LoggerInterface
    {
        $path = (string) ($config['path'] ?? 'php://stderr');
        $level = Logger::toMonologLevel($config['level'] ?? 'debug');

        $logger = new Logger($channel);
        $logger->pushHandler(new StreamHandler($path, $level));
        // Interpolate PSR-3 {placeholders} from context (and drop the used keys).
        $logger->pushProcessor(new PsrLogMessageProcessor(null, true));

        return $logger;
    }
}
