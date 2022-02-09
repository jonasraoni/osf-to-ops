<?php

declare(strict_types=1);

namespace PKP\OSF;

use Exception;

/**
 * Generic class to deal with unhandled exceptions and output console messages
 */
class Logger
{
    public static bool $verbose = true;

    /**
     * Logs the given text
     *
     * @param bool|null $replace If replace is true, a carriage-return will be added to the end, otherwise a new line
     */
    public static function log(string $message, ?bool $replace = false): void
    {
        if (static::$verbose) {
            echo($replace ? "\e[K${message}\r" : "${message}\n");
        }
    }

    public static function handleWarnings(): void
    {
        set_error_handler(function (int $code, string $message, string $file, int $line): bool {
            throw new Exception("${message} at ${file}:${line}", $code);
        });
    }
}
