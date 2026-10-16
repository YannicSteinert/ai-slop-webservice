<?php

declare(strict_types=1);

namespace bapersbw\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * A PSR-3 logger that writes to the "security" channel. It is a distinct type
 * (not just LoggerInterface) purely so the container can autowire the security
 * channel into auth-related classes, while everything else gets the default
 * application logger. It simply delegates to the wrapped Monolog logger.
 */
final class SecurityLogger extends AbstractLogger
{
    public function __construct(private readonly LoggerInterface $inner)
    {
    }

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $message, $context);
    }
}
