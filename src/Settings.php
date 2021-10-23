<?php

declare(strict_types=1);

namespace PKP\OSF;

use Exception;
use GetOpt\GetOpt;

class Settings
{
    /** @var string */
    public $user = '';

    /** @var string */
    public $locale = '';

    /** @var string */
    public $token = '';

    /** @var string */
    public $provider = '';

    /** @var string */
    public $context = '';

    /** @var string */
    public $output = '';

    /** @var string */
    public $memory = '';

    /** @var int */
    public $sleep = 0;

    /** @var string */
    public $baseUrl = '';

    /** @var bool */
    public $requireDoi = false;

    /** @var int */
    public $maxRetry = 0;

    /** @var bool */
    public $quiet = false;

    public static function createFromOptions(GetOpt $options): self
    {
        $settings = new static();
        foreach (array_keys(get_class_vars(static::class)) as $property) {
            $settings->$property = $options[$property];
            settype($settings->$property, gettype($settings->$property));
        }
        foreach (['token', 'provider', 'context', 'output', 'locale', 'memory', 'sleep'] as $required) {
            if (!$settings->$required) {
                throw new Exception("The setting ${required} is required");
            }
        }
        return $settings;
    }
}
