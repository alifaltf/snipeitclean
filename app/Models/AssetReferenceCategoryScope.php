<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * ERS Phase 5B1: hide rows of OTHER models that point at assets the current
 * user may not view (activity log entries, maintenances, checkout
 * acceptances), so reports, histories and exports built from them do not
 * list or name those assets.
 *
 * Each reference is either a plain asset id column (Maintenance.asset_id)
 * or a polymorphic type/id pair (Actionlog item/target, CheckoutAcceptance
 * checkoutable). A row is kept when every reference is either not an asset
 * or an asset the user may view. The allowed asset ids come from
 * AssetCategoryViewScope, so the rule and the unrestricted cases (Super
 * User, no logged-in user) are identical.
 *
 * Applied in the database, before counting, pagination and export.
 */
final class AssetReferenceCategoryScope implements Scope
{
    /**
     * @param  list<string|array{0: string, 1: string}>  $references  asset id column, or [type column, id column]
     */
    public function __construct(private readonly array $references) {}

    public function apply(Builder $builder, Model $model): void
    {
        $viewable = AssetCategoryViewScope::viewableAssetIds();
        if ($viewable === null) {
            return;
        }

        $table = $model->getTable();

        foreach ($this->references as $reference) {
            if (is_string($reference)) {
                $builder->where(fn ($query) => $query
                    ->whereNull($table.'.'.$reference)
                    ->orWhereIn($table.'.'.$reference, clone $viewable));

                continue;
            }

            [$typeColumn, $idColumn] = $reference;
            $builder->where(function ($query) use ($table, $typeColumn, $idColumn, $viewable) {
                /** @var QueryBuilder $inner */
                $inner = $query->getQuery();
                $inner->whereNull($table.'.'.$typeColumn)
                    ->orWhere($table.'.'.$typeColumn, '!=', Asset::class)
                    ->orWhereIn($table.'.'.$idColumn, clone $viewable);
            });
        }
    }
}
