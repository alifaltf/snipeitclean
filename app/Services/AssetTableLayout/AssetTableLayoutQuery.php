<?php

namespace App\Services\AssetTableLayout;

use App\Models\Category;
use App\Models\Company;
use App\Models\Setting;
use App\Services\AssetCategorySelection;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * ERS Phase 7B: a final asset category's effective table layout applied to
 * an asset query (the asset API in layout mode and the category CSV export).
 *
 * The table (API layout mode) and the CSV export build their asset list
 * with the same three calls, so they always contain the same assets:
 * applyListFilters() (the category, then the list filters the page and the
 * table send), applySearch() and applySort(). The Asset query's global
 * scopes (company scope, category View permissions) apply as everywhere.
 *
 * Every filter, search and sort field must be a column of the effective
 * layout, through the allowlist in AssetTableFieldCatalog::STANDARD_QUERY
 * (standard fields) or the field's own column (custom fields). A filter on
 * a field the layout does not show is ignored, so hidden fields can never
 * narrow, order or count the list. Encrypted custom fields are never
 * filtered, searched or sorted (their stored values are ciphertext). A
 * custom field only has a value for an asset whose current, live model's
 * fieldset contains it; values left in the column by an earlier fieldset
 * never match a search or filter and sort as no value. Anything else a
 * client sends (unknown or malformed fields, raw column names, arrays where
 * a single value is expected) is ignored. No category is named here.
 */
final class AssetTableLayoutQuery
{
    /** Opt-in request parameter of the asset API (value "1"). */
    public const PARAM = 'asset_table_layout';

    /** Longest search text used (longer input is cut). */
    private const MAX_SEARCH = 255;

    /** @param  list<AssetTableColumn>  $columns */
    private function __construct(
        public readonly Category $category,
        public readonly array $columns,
        public readonly bool $explicit,
    ) {}

    /**
     * The layout for an API request that asks for it (asset_table_layout=1)
     * and selects a final asset category the user may view; null otherwise
     * (the response is then the normal, unchanged asset list).
     */
    public static function forApiRequest(Request $request): ?self
    {
        if (! in_array($request->input(self::PARAM), ['1', 1, true], true) || ! $request->has(AssetCategorySelection::PARAM)) {
            return null;
        }

        return self::forSelection(AssetCategorySelection::fromValue($request->input(AssetCategorySelection::PARAM)));
    }

    /**
     * The layout of a selected node, when it is a final asset category with
     * a layout; null for no selection, navigation groups and anything else.
     */
    public static function forSelection(?AssetCategorySelection $selection): ?self
    {
        if ($selection === null || $selection->isNavigationGroup()) {
            return null;
        }
        $category = Category::query()->find($selection->id());
        $layouts = app(AssetTableLayoutService::class);
        if (! $layouts->isConfigurable($category)) {
            return null;
        }
        $columns = $layouts->effectiveColumns($category);

        return $columns === [] ? null : new self($category, $columns, $layouts->hasExplicitLayout($category));
    }

    /** @return list<string> */
    public function fields(): array
    {
        return array_map(fn (AssetTableColumn $column) => $column->field(), $this->columns);
    }

    /** Relations the layout's columns read (eager loaded to avoid N+1). */
    public function eagerLoads(): array
    {
        return ['model', 'model.category', 'model.manufacturer', 'model.fieldset.fields', 'status', 'company', 'location', 'defaultLoc', 'supplier', 'assignedTo'];
    }

    /**
     * Column metadata for API clients and the table, in layout order.
     *
     * @return list<array{key: string, field: string, title: string, kind: string, sortable: bool, searchable: bool, encrypted: bool}>
     */
    public function metadata(): array
    {
        return array_map(fn (AssetTableColumn $column) => [
            'key' => $column->key,
            'field' => $column->field(),
            'title' => $column->label,
            'kind' => $column->kind,
            'sortable' => $this->sortExpression($column) !== null,
            'searchable' => $this->searchExpression($column) !== null,
            'encrypted' => $column->encrypted,
        ], $this->columns);
    }

    /**
     * Text search over the layout's searchable columns (one term, matched
     * anywhere, any of the columns), plus per-column filters given as a JSON
     * object {"<field>": "<text>"} (all must match). Unknown or unsearchable
     * fields are ignored.
     */
    public function applySearch(Builder $assets, mixed $search, mixed $filter, mixed $operator = 'and'): void
    {
        $term = self::term($search);
        if ($term !== null) {
            $searchable = array_values(array_filter($this->columns, fn (AssetTableColumn $column) => $this->searchExpression($column) !== null));
            if ($searchable === []) {
                $assets->whereRaw('1 = 0');
            } else {
                $assets->where(function (Builder $query) use ($searchable, $term) {
                    foreach ($searchable as $column) {
                        $query->orWhere(fn (Builder $one) => $this->matchColumn($one, $column, $term));
                    }
                });
            }
        }

        $filters = [];
        foreach ($this->filters($filter) as $field => $value) {
            $column = $this->columnForField($field);
            if ($column !== null && $this->searchExpression($column) !== null) {
                $filters[] = [$column, $value];
            }
        }
        if ($filters !== []) {
            // The table's advanced search sends filter_operator "or" or "and".
            $any = $operator === 'or';
            $assets->where(function (Builder $query) use ($filters, $any) {
                foreach ($filters as [$column, $value]) {
                    $any
                        ? $query->orWhere(fn (Builder $one) => $this->matchColumn($one, $column, $value))
                        : $query->where(fn (Builder $one) => $this->matchColumn($one, $column, $value));
                }
            });
        }
    }

    /**
     * The category and the list filters of the asset list, each applied
     * only when its field is a column of the layout (hidden fields never
     * filter). Values must be single scalars of the expected shape; anything
     * else is ignored. Mirrors the asset API's own filters:
     *
     *  - status_type (or legacy "status"): "Deleted" lists deleted assets;
     *    Pending/RTD/Undeployable/Archived need the status column,
     *    Requestable the requestable column, Deployed the assigned-to
     *    column and byod the BYOD column. Without a usable status_type the
     *    list hides archived assets unless the "show archived" setting is on
     *    or a status_id filter applies, as the asset list does;
     *  - status_id (status), company_id (company, with
     *    expand_company_hierarchy), model_id (model), location_id
     *    (location), rtd_location_id (rtd_location), supplier_id
     *    (supplier), manufacturer_id (manufacturer), assigned_to +
     *    assigned_type (assigned_to), asset_tag, serial, order_number,
     *    asset_eol_date, byod, requestable=true: exact matches;
     *  - <custom field column>=<value>: exact match on an unencrypted
     *    custom field of the layout, for assets whose live model's
     *    fieldset contains it.
     *
     * Not applied (no corresponding layout column): category_id (the
     * category is the selected one), depreciation_id.
     */
    public function applyListFilters(Builder $assets, Request $request, Setting $settings): void
    {
        $assets->whereIn('assets.model_id', fn ($models) => $models->select('models.id')->from('models')
            ->where('models.category_id', (int) $this->category->id));

        $statusType = self::scalar($request->input('status_type')) ?? self::scalar($request->input('status'));
        $statusId = $this->allowsFilter('status') ? self::id($request->input('status_id')) : null;

        $applied = $statusType !== null && $this->applyStatusType($assets, $statusType);
        if (! $applied) {
            if ($statusId === null && $settings->show_archived_in_list != '1') {
                $assets->whereIn('assets.status_id', fn ($labels) => $labels->select('id')->from('status_labels')->where('archived', '=', 0));
            }
        }
        if ($statusId !== null) {
            $assets->where('assets.status_id', '=', $statusId);
        }

        foreach (['asset_tag' => 'asset_tag', 'serial' => 'serial', 'order_number' => 'order_number', 'asset_eol_date' => 'asset_eol_date'] as $param => $field) {
            if ($this->allowsFilter($field) && ($value = self::scalar($request->input($param))) !== null) {
                $assets->where('assets.'.$field, '=', $value);
            }
        }
        foreach (['location_id' => 'location', 'rtd_location_id' => 'rtd_location', 'supplier_id' => 'supplier'] as $param => $field) {
            if ($this->allowsFilter($field) && ($id = self::id($request->input($param))) !== null) {
                $assets->where('assets.'.$param, '=', $id);
            }
        }
        if ($this->allowsFilter('model') && ($ids = self::ids($request->input('model_id'))) !== []) {
            $assets->whereIn('assets.model_id', $ids);
        }
        if ($this->allowsFilter('manufacturer') && ($id = self::id($request->input('manufacturer_id'))) !== null) {
            $assets->whereIn('assets.model_id', fn ($models) => $models->select('models.id')->from('models')->where('models.manufacturer_id', $id));
        }
        if ($this->allowsFilter('company') && ($id = self::id($request->input('company_id'))) !== null) {
            $request->boolean('expand_company_hierarchy')
                ? $assets->whereIn('assets.company_id', Company::reachableCompanyIds($id))
                : $assets->where('assets.company_id', '=', $id);
        }
        if ($this->allowsFilter('assigned_to') && ($id = self::id($request->input('assigned_to'))) !== null
            && ($type = self::scalar($request->input('assigned_type'))) !== null) {
            $assets->where('assets.assigned_to', '=', $id)->where('assets.assigned_type', '=', $type);
        }
        if ($this->allowsFilter('byod') && in_array($request->input('byod'), ['0', '1', 0, 1], true)) {
            $assets->where('assets.byod', '=', (int) $request->input('byod'));
        }
        if ($this->allowsFilter('requestable') && $request->input('requestable') === 'true') {
            $assets->where('assets.requestable', '=', 1);
        }

        foreach ($this->columns as $column) {
            if (! $column->isCustomField() || $this->searchExpression($column) === null) {
                continue;
            }
            if (($value = self::scalar($request->input((string) $column->dbColumn))) !== null) {
                $assets->where('assets.'.$column->dbColumn, '=', $value)
                    ->whereIn('assets.model_id', $this->modelsUsing($column));
            }
        }
    }

    /**
     * Whether a list filter on a field may apply: the field is a column of
     * the layout (and, for a custom field, one that can be searched).
     */
    public function allowsFilter(string $field): bool
    {
        $column = $this->columnForField($field);
        if ($column === null) {
            return false;
        }

        return ! $column->isCustomField() || $this->searchExpression($column) !== null;
    }

    /** @return bool whether the status type was applied */
    private function applyStatusType(Builder $assets, string $statusType): bool
    {
        $labels = fn (array $flags) => fn ($query) => $query->select('id')->from('status_labels')->where($flags);

        switch ($statusType) {
            case 'Deleted':
                $assets->onlyTrashed();

                return true;
            case 'Pending':
            case 'RTD':
            case 'Undeployable':
            case 'Archived':
                if (! $this->allowsFilter('status')) {
                    return false;
                }
                match ($statusType) {
                    'Pending' => $assets->whereIn('assets.status_id', $labels(['deployable' => 0, 'pending' => 1, 'archived' => 0])),
                    'RTD' => $assets->whereNull('assets.assigned_to')->whereIn('assets.status_id', $labels(['deployable' => 1, 'pending' => 0, 'archived' => 0])),
                    'Undeployable' => $assets->Undeployable(),
                    'Archived' => $assets->whereIn('assets.status_id', $labels(['deployable' => 0, 'pending' => 0, 'archived' => 1])),
                };

                return true;
            case 'Requestable':
                if (! $this->allowsFilter('requestable')) {
                    return false;
                }
                $assets->where('assets.requestable', '=', 1)->whereIn('assets.status_id', $labels(['deployable' => 1, 'pending' => 0, 'archived' => 0]));

                return true;
            case 'Deployed':
                if (! $this->allowsFilter('assigned_to')) {
                    return false;
                }
                $assets->whereNotNull('assets.assigned_to');

                return true;
            case 'byod':
                if (! $this->allowsFilter('byod')) {
                    return false;
                }
                $assets->where('assets.byod', '=', 1);

                return true;
            default:
                return false;
        }
    }

    /**
     * Live models whose fieldset contains the custom field: the only assets
     * for which the field has a value.
     */
    private function modelsUsing(AssetTableColumn $column): Closure
    {
        return fn ($models) => $models->select('models.id')->from('models')
            ->whereNull('models.deleted_at')
            ->whereIn('models.fieldset_id', fn ($pivot) => $pivot->select('custom_fieldset_id')->from('custom_field_custom_fieldset')
                ->where('custom_field_id', (int) $column->customFieldId));
    }

    /**
     * Order by a layout column when $sort names a sortable one, otherwise by
     * creation date (the asset list's default). Ties are broken by id so
     * pagination is stable.
     */
    public function applySort(Builder $assets, mixed $sort, mixed $order): void
    {
        $direction = $order === 'asc' ? 'asc' : 'desc';
        $field = is_string($sort) ? preg_replace('/\Acustom_fields\./', '', $sort) : null;
        $column = is_string($field) ? $this->columnForField($field) : null;
        $expression = $column !== null ? $this->sortExpression($column) : null;

        if ($expression === null) {
            $assets->orderBy('assets.created_at', $direction);
        } elseif (str_starts_with($expression, 'scope:')) {
            $scope = substr($expression, strlen('scope:'));
            $assets->{$scope}($direction);
        } elseif ($column->isCustomField()) {
            // Only assets whose live model's fieldset contains the field have
            // a value; every other asset (stale values included) sorts as
            // having none.
            $value = str_starts_with($expression, 'numeric:') ? substr($expression, strlen('numeric:')).' * 1' : $expression;
            $assets->orderByRaw(
                'CASE WHEN assets.model_id IN (SELECT models.id FROM models WHERE models.deleted_at IS NULL AND models.fieldset_id IN '
                .'(SELECT custom_fieldset_id FROM custom_field_custom_fieldset WHERE custom_field_id = ?)) THEN '.$value.' ELSE NULL END '.$direction,
                [(int) $column->customFieldId]
            );
        } else {
            $assets->orderBy($expression, $direction);
        }
        $assets->orderBy('assets.id', $direction);
    }

    public function columnForField(string $field): ?AssetTableColumn
    {
        foreach ($this->columns as $column) {
            if ($column->field() === $field) {
                return $column;
            }
        }

        return null;
    }

    /** "assets.x", "numeric:assets.x", "scope:Name" or null. */
    public function sortExpression(AssetTableColumn $column): ?string
    {
        if ($column->isCustomField()) {
            if ($column->encrypted || ! self::safeColumn($column->dbColumn)) {
                return null;
            }

            return ($column->format === 'NUMERIC' ? 'numeric:' : '').'assets.'.$column->dbColumn;
        }

        return AssetTableFieldCatalog::STANDARD_QUERY[$column->field()]['sort'] ?? null;
    }

    /** "assets.x", "relation:path.column" or null. */
    public function searchExpression(AssetTableColumn $column): ?string
    {
        if ($column->isCustomField()) {
            if ($column->encrypted || ! self::safeColumn($column->dbColumn) || in_array($column->format, ['DATE', 'DATETIME'], true)) {
                return null;
            }

            return 'assets.'.$column->dbColumn;
        }

        return AssetTableFieldCatalog::STANDARD_QUERY[$column->field()]['search'] ?? null;
    }

    private function matchColumn(Builder $query, AssetTableColumn $column, string $term): void
    {
        $expression = (string) $this->searchExpression($column);
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        if (str_starts_with($expression, 'relation:')) {
            $path = substr($expression, strlen('relation:'));
            $relation = substr($path, 0, (int) strrpos($path, '.'));
            $attribute = substr($path, (int) strrpos($path, '.') + 1);
            $query->whereHas($relation, function (Builder $related) use ($attribute, $pattern) {
                $related->whereRaw($related->getModel()->qualifyColumn($attribute)." LIKE ? ESCAPE '!'", [$pattern]);
            });

            return;
        }

        $query->whereRaw($expression." LIKE ? ESCAPE '!'", [$pattern]);

        // A custom field only has a value for assets whose live model's
        // fieldset contains it (as the asset list shows); values left in
        // the column by an earlier fieldset never match.
        if ($column->isCustomField()) {
            $query->whereIn('assets.model_id', $this->modelsUsing($column));
        }
    }

    /** @return array<string, string> */
    private function filters(mixed $filter): array
    {
        if (is_string($filter)) {
            $filter = json_decode($filter, true);
        }
        if (! is_array($filter)) {
            return [];
        }
        $filters = [];
        foreach ($filter as $field => $value) {
            $term = self::term($value);
            if (is_string($field) && $term !== null) {
                $filters[preg_replace('/\Acustom_fields\./', '', $field)] = $term;
            }
        }

        return $filters;
    }

    private static function term(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $term = trim(mb_substr((string) $value, 0, self::MAX_SEARCH));

        return $term === '' ? null : $term;
    }

    /** A single non-empty string or integer value, as a string; otherwise null. */
    private static function scalar(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > self::MAX_SEARCH) {
            return null;
        }

        return $value;
    }

    private static function id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && preg_match('/\A[1-9][0-9]{0,17}\z/', $value) === 1 ? (int) $value : null;
    }

    /** One id, or a list of ids (all must be valid); otherwise []. */
    private static function ids(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        if ($values === [] || count($values) > 500) {
            return [];
        }
        $ids = [];
        foreach ($values as $one) {
            if (($id = self::id($one)) === null) {
                return [];
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private static function safeColumn(?string $column): bool
    {
        return $column !== null && preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $column) === 1;
    }
}
