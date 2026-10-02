<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERS Phase 6A: one user's secure asset CSV import.
 *
 * Nothing is mass assignable; the import actions set every column
 * explicitly. URLs use public_id (a random UUID), never the primary key.
 *
 * Lifecycle: uploaded -> target_selected -> mapped (Phase 6A), then
 * validated -> importing -> completed | failed (later phases). A session is
 * usable only by its owner, only while it is open and not past expires_at.
 */
class AssetImportSession extends Model
{
    public const STATE_UPLOADED = 'uploaded';

    public const STATE_TARGET_SELECTED = 'target_selected';

    public const STATE_MAPPED = 'mapped';

    public const STATE_VALIDATED = 'validated';

    public const STATE_IMPORTING = 'importing';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    public const STATE_EXPIRED = 'expired';

    public const STATES = [
        self::STATE_UPLOADED,
        self::STATE_TARGET_SELECTED,
        self::STATE_MAPPED,
        self::STATE_VALIDATED,
        self::STATE_IMPORTING,
        self::STATE_COMPLETED,
        self::STATE_FAILED,
        self::STATE_EXPIRED,
    ];

    /** States in which the Phase 6A steps may be used. */
    public const OPEN_STATES = [
        self::STATE_UPLOADED,
        self::STATE_TARGET_SELECTED,
        self::STATE_MAPPED,
    ];

    /** Where a value comes from: one fixed record, a CSV column, or nowhere. */
    public const SOURCE_FIXED = 'fixed';

    public const SOURCE_COLUMN = 'column';

    public const SOURCE_NONE = 'none';

    protected $table = 'asset_import_sessions';

    /** No mass assignment of any column. */
    protected $guarded = ['*'];

    protected $casts = [
        'created_by' => 'integer',
        'file_size' => 'integer',
        'headers' => 'array',
        'row_count' => 'integer',
        'category_id' => 'integer',
        'model_id' => 'integer',
        'status_id' => 'integer',
        'company_id' => 'integer',
        'location_id' => 'integer',
        'mapping' => 'array',
        'expires_at' => 'datetime',
        'validated_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** Sessions of $user that can still be continued. */
    public function scopeOpenFor(Builder $query, User $user): Builder
    {
        return $query->where('created_by', $user->id)
            ->whereIn('state', self::OPEN_STATES)
            ->where('expires_at', '>', now());
    }

    public function isOpen(): bool
    {
        return in_array($this->state, self::OPEN_STATES, true)
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /** @return list<string> */
    public function headerList(): array
    {
        return array_values(array_map('strval', (array) $this->headers));
    }

    /**
     * Saved destination per column position (null = do not import), or an
     * empty array when no mapping has been saved.
     *
     * @return array<int, string|null>
     */
    public function mappedDestinations(): array
    {
        $destinations = [];
        foreach ((array) $this->mapping as $entry) {
            if (is_array($entry) && isset($entry['column']) && is_int($entry['column'])) {
                $destinations[$entry['column']] = isset($entry['destination']) && is_string($entry['destination'])
                    ? $entry['destination']
                    : null;
            }
        }

        return $destinations;
    }
}
