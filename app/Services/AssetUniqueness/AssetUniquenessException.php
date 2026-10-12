<?php

namespace App\Services\AssetUniqueness;

use RuntimeException;

/**
 * ERS: a uniqueness rule could not be enforced (existing duplicates, an
 * object ERS does not own, a schema lock that could not be taken, or a
 * failed schema change). Messages are safe to show: counts and field
 * names only, never values, SQL or credentials.
 */
final class AssetUniquenessException extends RuntimeException
{
    public const CONFLICTS = 'conflicts';

    public const FOREIGN_OBJECT = 'foreign_object';

    public const LOCKED = 'locked';

    public const SCHEMA_CHANGE_FAILED = 'schema_change_failed';

    /** @param  array<string, int>  $conflicts  rule label => number of duplicate groups */
    public function __construct(public readonly string $reason, string $message, public readonly array $conflicts = [], public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
