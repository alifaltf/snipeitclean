<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERS Phase 7A: one column of a final asset category's asset table.
 *
 * Holds only a field key ("standard:<field>" or "custom_field:<id>") and a
 * 1-based position. Nothing is mass assignable: rows are written only by
 * App\Services\AssetTableLayout\AssetTableLayoutService, which validates
 * every key and replaces the whole layout in one transaction. Read the
 * effective layout through that service, never from these rows directly:
 * stored keys can become ineligible later (a model or fieldset changes)
 * and are then ignored, not shown.
 */
class AssetCategoryColumn extends Model
{
    protected $table = 'asset_category_columns';

    /** No mass assignment of any column. */
    protected $guarded = ['*'];

    protected $casts = [
        'category_id' => 'integer',
        'position' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
