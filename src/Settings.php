<?php

declare(strict_types=1);

namespace PKP\OSF;

use Exception;
use GetOpt\GetOpt;

class Settings
{
    public string $user = '';

    public string $locale = '';

    public string $token = '';

    public string $provider = '';

    public string $context = '';

    public string $output = '';

    public string $memory = '';

    public int $sleep = 0;

    public string $baseUrl = '';

    public bool $requireDoi = false;

    public int $maxRetry = 0;

    public bool $quiet = false;

    public string $email = '';

    public static function createFromOptions(GetOpt $options): self
    {
        $settings = new static();
        foreach (array_keys(get_class_vars(static::class)) as $property) {
            $value = $options[$property];
            settype($value, gettype($settings->$property));
            $settings->$property = $value;
        }

        foreach (['token', 'provider', 'context', 'output', 'locale', 'memory', 'sleep', 'email'] as $required) {
            if (!$settings->$required) {
                throw new Exception("The setting ${required} is required");
            }
        }
        return $settings;
    }
}
