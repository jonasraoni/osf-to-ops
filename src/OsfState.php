<?php

declare(strict_types=1);

namespace PKP\OSF;

/**
 * Mapping for the possible OSF preprint status (perhaps it's dynamic and other publications might use different values?!)
 */
class OsfState
{
    public const INITIAL = 'initial';
    public const WITHDRAWN = 'withdrawn';
    public const ACCEPTED = 'accepted';
}
