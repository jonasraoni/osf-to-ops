<?php

declare(strict_types=1);

namespace PKP\OSF;

/**
 * Mapping for the Native Import Export plugin representing the values for the <id>.type attribute
 */
class Identifier
{
    public const PUBLIC = 'public';
    public const INTERNAL = 'internal';
    public const DOI = 'doi';
}
