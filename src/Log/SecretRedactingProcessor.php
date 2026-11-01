<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Log;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Replaces registered secrets (the bot token above all: it is part of every
 * Telegram file download URL, so it ends up in HTTP client error messages)
 * with a placeholder before a record reaches any handler.
 *
 * The registry is static so that every logger instance in a process, whether
 * created at startup, autowired by the DI container or unserialized inside a
 * worker, redacts the same values. Each process registers its secrets on entry.
 */
final class SecretRedactingProcessor implements ProcessorInterface
{
    public const string PLACEHOLDER = '***';

    /** @var list<string> */
    private static array $secrets = [];

    public static function registerSecret(string $secret): void
    {
        if ($secret === '' || in_array($secret, self::$secrets, true)) {
            return;
        }
        self::$secrets[] = $secret;
    }

    public static function redact(string $text): string
    {
        return self::$secrets === [] ? $text : str_replace(self::$secrets, self::PLACEHOLDER, $text);
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if (self::$secrets === []) {
            return $record;
        }

        return $record->with(message: self::redact($record->message));
    }
}
