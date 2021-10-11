<?php

declare(strict_types=1);

namespace PKP\OSF;

class Logger
{
    /** @var bool */
    public static $verbose = true;

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
}
