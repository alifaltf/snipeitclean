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
 * No category ids or names are hard-coded.
 */
final class AssetCategoryNavigation
{
    /**
     * @return list<array{id: int, name: string, group: bool, depth: int, active: bool, ancestor: bool, open: bool, children: list<array<string, mixed>>}>
     */
    public static function items(AssetCategoryTree $tree, ?AssetCategorySelection $selection = null): array
    {
        $activeId = $selection?->id();
        $ancestorIds = $selection ? array_flip($selection->ancestorIds()) : [];

        $build = function (array $nodes) use (&$build, $tree, $activeId, $ancestorIds): array {
            $items = [];
            foreach ($nodes as $node) {
                if (! self::isVisible($tree, $node)) {
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

    private static function isVisible(AssetCategoryTree $tree, AssetCategoryTreeNode $node): bool
    {
        return $node->isAssignable || $tree->assignableIdsWithin($node->id) !== [];
    }
}
