<?php

namespace App\Services\AssetImport\Execution;

use App\Models\AssetImportSession;

/**
 * ERS Phase 6C: what one execution request ended with. Only "completed"
 * created anything.
 */
final class AssetImportExecutionOutcome
{
    public const COMPLETED = 'completed';

    /** The session is not in the validated state. */
    public const NOT_VALIDATED = 'not_validated';

    /** The confirmation does not match the validation the user reviewed. */
    public const NOT_CONFIRMED = 'not_confirmed';

    /** The target or mapping is no longer valid for this user. */
    public const NEEDS_RECONFIGURATION = 'needs_reconfiguration';

    /** Validation inside the transaction found errors; nothing was written. */
    public const REVALIDATION_FAILED = 'revalidation_failed';

    /** Creating the assets failed; everything was rolled back. */
    public const EXECUTION_FAILED = 'execution_failed';

    /** Another import holds the import lock. Nothing was written. */
    public const BUSY = 'busy';

    /** @param  list<int>  $assetIds */
    private function __construct(
        public readonly string $status,
        public readonly ?AssetImportSession $session,
        public readonly array $assetIds = [],
        public readonly ?string $problem = null,
    ) {}

    /** @param  list<int>  $assetIds */
    public static function completed(AssetImportSession $session, array $assetIds): self
    {
        return new self(self::COMPLETED, $session, $assetIds);
    }

    public static function of(string $status, ?AssetImportSession $session, ?string $problem = null): self
    {
        return new self($status, $session, [], $problem);
    }
}
