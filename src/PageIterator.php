<?php

declare(strict_types=1);

namespace PKP\OSF;

use Generator;
use GuzzleHttp\Client;

/**
 * Generic iterator to pass through the pages of an OSF API node
 */
class PageIterator
{
    public static function create(Client $client, string $url): Generator
    {
        do {
            $response = json_decode((string) $client->get($url)->getBody(), false);
            foreach ($response->data as $item) {
                yield $item;
            }
            $url = $response->links->next;
        } while ($url);
    }

    public static function createFromJson(Client $client, object $response): Generator
    {
        while ($response) {
            foreach ($response->data as $item) {
                yield $item;
            }
            $url = $response->links->next;
            $response = $url ? json_decode((string) $client->get($url)->getBody(), false) : null;
        }
    }
}
