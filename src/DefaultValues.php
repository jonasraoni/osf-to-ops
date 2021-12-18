<?php

declare(strict_types=1);

namespace PKP\OSF;

/**
 * Default values to use when importing the preprints
 */
class DefaultValues
{
    public const STAGE = 'proof';
    public const GENRE = 'Preprint Text';
    public const GENRE_ABBREVIATION = 'PRE';
    public const OTHER_GENRE = 'Other';
    public const OTHER_GENRE_ABBREVIATION = 'OTHER';
    public const USER_GROUP = 'Author';
    public const PUBLICATION_RELATION = 3; //3 = PUBLICATION_RELATION_PUBLISHED
}
