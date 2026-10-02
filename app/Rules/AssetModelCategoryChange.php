<?php

namespace App\Rules;

use App\Models\AssetModel;
use App\Services\AssetCategoryWriteAuthorizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ERS Phase 5B2: changing an existing Asset Model's category moves every
 * asset of that model into the new category. A category-restricted user may
 * only do that with asset-category Edit on BOTH the old and the new final
 * category (Super Admin is unrestricted). New models, models without assets
 * and unchanged categories are not affected. Decided by
 * AssetCategoryWriteAuthorizer.
 */
final class AssetModelCategoryChange implements ValidationRule
{
    public function __construct(private readonly AssetModel $model) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->model->exists) {
            return;
        }

        $from = $this->model->getOriginal('category_id');
        if (! app(AssetCategoryWriteAuthorizer::class)->allowsModelCategoryChange((int) $this->model->getKey(), $from, $value)) {
            $fail('admin/models/message.category_change_requires_asset_edit')->translate();
        }
    }
}
