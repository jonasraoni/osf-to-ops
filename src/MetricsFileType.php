<?php

declare(strict_types=1);

namespace PKP\OSF;

/**
 * Mapping for the OPS metrics table (used when importing downloads statistics)
 */
class MetricsFileType
{
    public const HTML = 1;
    public const PDF = 2;
    public const OTHER = 3;
    public const DOC = 4;
}
