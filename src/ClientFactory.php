<?php

declare(strict_types=1);

namespace PKP\OSF;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

class ClientFactory
{
    public static function create(string $token): Client
    {
        return new Client([
            'base_uri' => 'https://api.osf.io/v2/preprints/',
            RequestOptions::VERIFY => false,
            RequestOptions::CONNECT_TIMEOUT => 20,
            RequestOptions::TIMEOUT => 120,
            RequestOptions::READ_TIMEOUT => 10,
            RequestOptions::AUTH => 1,
            RequestOptions::HEADERS => [
                'Authorization' => "Bearer {$token}"
            ]
        ]);
    }
}
