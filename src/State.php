<?php

declare(strict_types=1);

namespace PKP\OSF;

/**
 * Mapping for the available preprint status in OPS
 */
class State
{
    public const QUEUED = 1;
    public const PUBLISHED = 3;
    public const DECLINED = 4;
    public const SCHEDULED = 5;
}
