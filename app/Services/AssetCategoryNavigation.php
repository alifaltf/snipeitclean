<?php

namespace App\Services;

/**
 * ERS Phase 4: the sidebar view of the asset category hierarchy.
 *
 * Built from AssetCategoryTree (one query, live asset categories only,
 * ordered by sort_order then name, depth-limited), so this class adds no
 * tree logic of its own; it only decides what is shown and highlighted:
 *
 *  - final/assignable categories are always shown;
 *  - navigation groups are shown only when at least one live assignable
 *    category exists somewhere below them (empty groups are hidden);
 *  - the selected node is "active" and its ancestors are "open".
 *
 * ERS Phase 5B1: for category-restricted users only categories they may
 * view are shown, so inaccessible category names never appear.
 *
 * No category ids or names are hard-coded.
 */
final class AssetCategoryNavigation
{
    /**
     * @return list<array{id: int, name: string, group: bool, depth: int, active: bool, ancestor: bool, open: bool, children: list<array<string, mixed>>}>
     */
    public static function items(AssetCategoryTree $tree, ?AssetCategorySelection $selection = null, ?AssetCategoryAccess $access = null): array
    {
        $activeId = $selection?->id();
        $ancestorIds = $selection ? array_flip($selection->ancestorIds()) : [];

        $build = function (array $nodes) use (&$build, $tree, $activeId, $ancestorIds, $access): array {
            $items = [];
            foreach ($nodes as $node) {
                if (! self::isVisible($tree, $node, $access)) {
                    continue;
                }

                $isAncestor = isset($ancestorIds[$node->id]);
                $items[] = [
                    'id' => $node->id,
                    'name' => $node->name,
                    'group' => $node->isNavigationOnly(),
                    'depth' => $tree->depth($node->id),
                    'active' => $node->id === $activeId,
                    'ancestor' => $isAncestor,
                    'open' => $isAncestor || $node->id === $activeId,
                    'children' => $node->isNavigationOnly() ? $build($tree->children($node->id)) : [],
                ];
            }

            return $items;
        };

        return $build($tree->roots());
    }

    /**
     * ERS Phase 5B1: with an $access (a category-restricted user) only
     * granted final categories, and groups with a granted final descendant,
     * are shown. Without one (Super User) every live final category and
     * every non-empty group is shown, as before.
     */
    private static function isVisible(AssetCategoryTree $tree, AssetCategoryTreeNode $node, ?AssetCategoryAccess $access): bool
    {
        if ($access !== null) {
            return $access->allowsWithin(AssetCategoryAccess::VIEW, $node->id);
        }

        return $node->isAssignable || $tree->assignableIdsWithin($node->id) !== [];
    }
}
