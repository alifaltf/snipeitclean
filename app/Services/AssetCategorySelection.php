<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ERS Phase 4: a single asset-category node selected through the
 * ?asset_category=<id> request parameter, resolved entirely server-side.
 *
 * The client only ever supplies ONE node id. Which category ids that node
 * stands for (itself for a final category, every live assignable
 * descendant for a navigation group) is resolved here from the live tree
 * (AssetCategoryTree), never taken from the client. Anything that is not a
 * single positive integer naming a live asset category (arrays,
 * comma-separated lists, text, missing, soft-deleted or non-asset ids)
 * resolves to null, which callers treat as "no category filter".
 *
 * No category ids or names are hard-coded.
 */
final class AssetCategorySelection
{
    /** The only request parameter this filter reads. */
    public const PARAM = 'asset_category';

    private const REQUEST_ATTRIBUTE = 'ers.asset_category_selection';

    private const TREE_ATTRIBUTE = 'ers.asset_category_tree';

    /**
     * @param  list<AssetCategoryTreeNode>  $path  root .. selected node
     * @param  list<int>  $assignableIds  final categories at or below the node
     */
    private function __construct(
        public readonly AssetCategoryTreeNode $node,
        public readonly array $path,
        public readonly array $assignableIds,
    ) {}

    /**
     * Resolve a raw request value. Returns null for anything invalid.
     */
    public static function fromValue(mixed $value, ?AssetCategoryTree $tree = null): ?self
    {
        $id = self::normalizeId($value);
        if ($id === null) {
            return null;
        }

        $tree ??= AssetCategoryTree::load();

        // The tree only contains live asset categories, so missing,
        // soft-deleted and non-asset ids are all rejected here.
        if (! $tree->has($id)) {
            return null;
        }

        return new self($tree->node($id), $tree->path($id), $tree->assignableIdsWithin($id));
    }

    /**
     * The selection for the current request, resolved once and memoised on
     * the request (the controller, breadcrumbs and sidebar all ask).
     */
    public static function forRequest(Request $request): ?self
    {
        if (! $request->attributes->has(self::REQUEST_ATTRIBUTE)) {
            $selection = $request->has(self::PARAM)
                ? self::fromValue($request->input(self::PARAM), self::treeForRequest($request))
                : null;
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $selection);
        }

        return $request->attributes->get(self::REQUEST_ATTRIBUTE);
    }

    /**
     * The live asset category tree for the current request, loaded once.
     */
    public static function treeForRequest(Request $request): AssetCategoryTree
    {
        if (! $request->attributes->has(self::TREE_ATTRIBUTE)) {
            $request->attributes->set(self::TREE_ATTRIBUTE, AssetCategoryTree::load());
        }

        return $request->attributes->get(self::TREE_ATTRIBUTE);
    }

    public function id(): int
    {
        return $this->node->id;
    }

    public function isNavigationGroup(): bool
    {
        return $this->node->isNavigationOnly();
    }

    /** @return list<int> ids of the selected node's ancestors (root first) */
    public function ancestorIds(): array
    {
        return array_map(fn (AssetCategoryTreeNode $node): int => $node->id, array_slice($this->path, 0, -1));
    }

    /** @return list<string> category names from the root to the selected node */
    public function pathNames(): array
    {
        return array_map(fn (AssetCategoryTreeNode $node): string => $node->name, $this->path);
    }

    /**
     * Restrict an asset query to assets whose model uses one of the resolved
     * categories. Composes with every other filter and with the Asset
     * model's company scoping; it only ever narrows the result. A group with
     * no assignable descendants matches nothing.
     */
    public function applyTo(Builder $assets): Builder
    {
        return $assets->whereIn(
            'assets.model_id',
            DB::table('models')->select('id')->whereIn('category_id', $this->assignableIds)
        );
    }

    /** A single positive integer (int or digit string); anything else is invalid. */
    private static function normalizeId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        // Up to 18 digits so the cast can never overflow.
        if (is_string($value) && preg_match('/\A[0-9]{1,18}\z/', $value) === 1) {
            $id = (int) $value;

            return $id > 0 ? $id : null;
        }

        return null;
    }
}
