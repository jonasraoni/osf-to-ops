<?php

declare(strict_types=1);

namespace PKP\OSF;

class Settings
{
    /** @var string */
    public $username;

    /** @var string */
    public $locale;

    public function __construct(string $username, string $locale)
    {
        $this->username = $username;
        $this->locale = $locale;
    }
}
