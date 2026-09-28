<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERS Phase 5A: one permission group's grants on one final asset category.
 *
 * Nothing on this model is mass assignable: rows are written only by
 * App\Actions\Groups\SaveGroupAssetCategoryPermissionsAction, which
 * validates every category id against the live tree first. Effective
 * permissions are resolved by App\Services\AssetCategoryPermissionService.
 */
class AssetCategoryPermission extends Model
{
    protected $table = 'asset_category_permissions';

    /** No mass assignment of any column. */
    protected $guarded = ['*'];

    protected $casts = [
        'group_id' => 'integer',
        'category_id' => 'integer',
        'can_view' => 'boolean',
        'can_create' => 'boolean',
        'can_update' => 'boolean',
        'can_delete' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
