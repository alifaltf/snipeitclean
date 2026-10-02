<?php

namespace App\Services\AssetImport;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Company;
use App\Models\Location;
use App\Models\Statuslabel;
use App\Models\User;
use App\Services\AssetCategoryAccess;
use App\Services\AssetCategoryPermissionService;
use App\Services\AssetCategoryTree;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * ERS Phase 6A: who may use the secure asset CSV import, and which targets
 * they may choose. Category decisions come from the Phase 5 permission
 * service (category Create, already capped by global assets.create; Super
 * Admin = every live final asset category); this class only adds the
 * global `import` permission and the "model belongs to the category" rule.
 *
 * Every check answers false for anything that is not a valid, authorised
 * choice, without saying why, so a hidden record and a missing one look
 * the same.
 */
final class AssetImportAuthorizer
{
    public function __construct(private readonly AssetCategoryPermissionService $permissions) {}

    /**
     * May $user use the import at all: global `import`, global assets.create
     * and category Create on at least one final category.
     */
    public function mayUse(User $user): bool
    {
        $gate = Gate::forUser($user);

        return $gate->allows('import')
            && $gate->allows('create', Asset::class)
            && $this->access($user)->categoryIds(AssetCategoryAccess::CREATE) !== [];
    }

    /**
     * May $user start an import into this specific final category (the
     * per-category "Import CSV" entry point): mayUse() plus category Create
     * on exactly that category.
     */
    public function mayImportInto(User $user, mixed $categoryId): bool
    {
        return $this->mayUse($user) && $this->allowsCategory($user, $categoryId);
    }

    /** Is $categoryId a final category $user may import into? */
    public function allowsCategory(User $user, mixed $categoryId): bool
    {
        $id = self::positiveInt($categoryId);

        return $id !== null && $this->access($user)->allows(AssetCategoryAccess::CREATE, $id);
    }

    /**
     * Final categories $user may import into, as id => "Parent > Child"
     * labels in tree order.
     *
     * @return array<int, string>
     */
    public function categoryOptions(User $user): array
    {
        $tree = AssetCategoryTree::load();
        $options = [];
        foreach ($this->access($user)->categoryIds(AssetCategoryAccess::CREATE) as $id) {
            $options[$id] = implode(' > ', array_map(fn ($node) => $node->name, $tree->path($id)));
        }

        return $options;
    }

    /**
     * Live models in an authorised category (empty for anything else).
     *
     * @return Collection<int, AssetModel>
     */
    public function modelOptions(User $user, mixed $categoryId): Collection
    {
        if (! $this->allowsCategory($user, $categoryId)) {
            return collect();
        }

        return AssetModel::query()
            ->where('category_id', (int) $categoryId)
            ->orderBy('name')
            ->get(['id', 'name', 'model_number', 'category_id', 'fieldset_id']);
    }

    /** The live model $modelId when it belongs to the authorised category, else null. */
    public function model(User $user, mixed $categoryId, mixed $modelId): ?AssetModel
    {
        $id = self::positiveInt($modelId);
        if ($id === null || ! $this->allowsCategory($user, $categoryId)) {
            return null;
        }

        return AssetModel::query()
            ->whereKey($id)
            ->where('category_id', (int) $categoryId)
            ->first();
    }

    /** @return Collection<int, Statuslabel> */
    public function statusOptions(): Collection
    {
        return Statuslabel::query()->orderBy('name')->get(['id', 'name']);
    }

    public function status(mixed $statusId): ?Statuslabel
    {
        $id = self::positiveInt($statusId);

        return $id === null ? null : Statuslabel::query()->find($id, ['id', 'name']);
    }

    /**
     * Companies the current user may use: the normal company-scoped query,
     * so Full Multiple Company Support limits non-Super-Admins to their own
     * companies.
     *
     * @return Collection<int, Company>
     */
    public function companyOptions(): Collection
    {
        return Company::query()->orderBy('name')->get(['id', 'name']);
    }

    public function company(mixed $companyId): ?Company
    {
        $id = self::positiveInt($companyId);

        return $id === null ? null : Company::query()->find($id, ['id', 'name']);
    }

    /**
     * Locations the current user may use (the normal company-scoped query;
     * location never grants or restricts asset access).
     *
     * @return Collection<int, Location>
     */
    public function locationOptions(): Collection
    {
        return Location::query()->orderBy('name')->get(['id', 'name']);
    }

    public function location(mixed $locationId): ?Location
    {
        $id = self::positiveInt($locationId);

        return $id === null ? null : Location::query()->find($id);
    }

    private function access(User $user): AssetCategoryAccess
    {
        return $this->permissions->forUser($user);
    }

    /** A positive integer from an int or digit-only string, else null. */
    public static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/\A[0-9]{1,9}\z/', $value) === 1) {
            $int = (int) $value;

            return $int > 0 ? $int : null;
        }

        return null;
    }
}
