<?php

namespace App\Services;

use App\Models\Category;

/**
 * Read-only, in-memory view of the asset category hierarchy (ERS Feature 1).
 *
 * The whole tree is built in PHP from ONE query over live asset categories,
 * so it behaves identically on SQLite, MariaDB/MySQL (including 5.7, which
 * has no recursive CTEs) and PostgreSQL. Asset trees are small (tens of
 * nodes), so loading everything is cheaper and simpler than per-node
 * queries.
 *
 * The builder never trusts stored data. A node whose stored parent cannot
 * be used is "detached": it is shown at the root and reported via
 * detached(), instead of crashing, looping forever or being hidden. Reasons:
 *
 *  - missing_parent:        parent row does not exist, is soft-deleted, or
 *                           is not an asset category (non-asset and trashed
 *                           rows are never loaded);
 *  - self_parent:           parent_id points at the node itself;
 *  - parent_not_navigation: parent is an assignable (final) category, which
 *                           may not have children;
 *  - cycle:                 the node is part of a circular ancestry chain;
 *  - max_depth:             the node would sit deeper than MAX_DEPTH.
 *
 * No category IDs or names are hard-coded here; callers pass IDs they
 * received and unknown IDs simply produce empty/null results.
 */
final class AssetCategoryTree
{
    /** Maximum number of levels, counting a root node as depth 1. */
    public const MAX_DEPTH = 8;

    public const DETACHED_MISSING_PARENT = 'missing_parent';

    public const DETACHED_SELF_PARENT = 'self_parent';

    public const DETACHED_PARENT_NOT_NAVIGATION = 'parent_not_navigation';

    public const DETACHED_CYCLE = 'cycle';

    public const DETACHED_MAX_DEPTH = 'max_depth';

    /** @var array<int, AssetCategoryTreeNode> */
    private array $nodes = [];

    /** @var array<int, list<int>> effective parent id => ordered child ids */
    private array $childIds = [];

    /** @var list<int> ordered root ids */
    private array $rootIds = [];

    /**
     * @param  array<int, AssetCategoryTreeNode>  $nodes
     * @param  array<int, list<int>>  $childIds
     * @param  list<int>  $rootIds
     */
    private function __construct(array $nodes, array $childIds, array $rootIds)
    {
        $this->nodes = $nodes;
        $this->childIds = $childIds;
        $this->rootIds = $rootIds;
    }

    /**
     * Load the live asset hierarchy with a single query. Soft-deleted and
     * non-asset categories are excluded by the query itself.
     */
    public static function load(): self
    {
        $rows = Category::query()
            ->assetCategories()
            ->toBase()
            ->get(['id', 'name', 'parent_id', 'is_assignable', 'sort_order']);

        return self::fromRows($rows);
    }

    /**
     * Build a tree from already-fetched rows (arrays, stdClass or models)
     * exposing id, name, parent_id, is_assignable and sort_order. Callers
     * must pass only live asset categories; anything referencing a row not
     * in the set is treated as detached.
     *
     * @param  iterable<mixed>  $rows
     */
    public static function fromRows(iterable $rows): self
    {
        // 1. Normalise input defensively.
        $raw = [];
        foreach ($rows as $row) {
            $id = self::toPositiveInt(data_get($row, 'id'));
            if ($id === null || isset($raw[$id])) {
                continue; // invalid or duplicate id: keep the first occurrence
            }

            $assignable = data_get($row, 'is_assignable');
            $sortOrder = data_get($row, 'sort_order');

            $raw[$id] = [
                'name' => (string) data_get($row, 'name', ''),
                'stored_parent' => self::toPositiveInt(data_get($row, 'parent_id')),
                // NULL/missing matches the column default: assignable.
                'assignable' => $assignable === null ? true : (bool) (int) $assignable,
                'sort_order' => is_numeric($sortOrder) ? max(0, (int) $sortOrder) : 0,
            ];
        }

        // 2. Resolve each stored parent pointer to a usable effective parent.
        $parent = [];
        $reason = [];
        foreach ($raw as $id => $node) {
            $parentId = $node['stored_parent'];
            $parent[$id] = null;

            if ($parentId === null) {
                continue;
            }
            if ($parentId === $id) {
                $reason[$id] = self::DETACHED_SELF_PARENT;
            } elseif (! isset($raw[$parentId])) {
                $reason[$id] = self::DETACHED_MISSING_PARENT;
            } elseif ($raw[$parentId]['assignable']) {
                $reason[$id] = self::DETACHED_PARENT_NOT_NAVIGATION;
            } else {
                $parent[$id] = $parentId;
            }
        }

        // 3. Break circular ancestry. Iterative walk; each node visited once.
        $state = []; // absent = unvisited, 1 = on current path, 2 = finished
        foreach (array_keys($raw) as $start) {
            if (isset($state[$start])) {
                continue;
            }
            $path = [];
            $current = $start;
            while ($current !== null && ! isset($state[$current])) {
                $state[$current] = 1;
                $path[] = $current;
                $current = $parent[$current];
            }
            if ($current !== null && $state[$current] === 1) {
                $cycleStart = array_search($current, $path, true);
                foreach (array_slice($path, $cycleStart) as $member) {
                    $parent[$member] = null;
                    $reason[$member] = self::DETACHED_CYCLE;
                }
            }
            foreach ($path as $visited) {
                $state[$visited] = 2;
            }
        }

        // 4. The links now form a forest. Assign depths breadth-first from
        //    the roots, detaching anything that would exceed MAX_DEPTH.
        $children = [];
        $roots = [];
        foreach ($parent as $id => $parentId) {
            if ($parentId === null) {
                $roots[] = $id;
            } else {
                $children[$parentId][] = $id;
            }
        }

        $depth = [];
        $queue = [];
        foreach ($roots as $rootId) {
            $depth[$rootId] = 1;
            $queue[] = $rootId;
        }
        for ($i = 0; $i < count($queue); $i++) {
            $id = $queue[$i];
            $kept = [];
            foreach ($children[$id] ?? [] as $childId) {
                if ($depth[$id] + 1 > self::MAX_DEPTH) {
                    $parent[$childId] = null;
                    $reason[$childId] = self::DETACHED_MAX_DEPTH;
                    $depth[$childId] = 1;
                    $roots[] = $childId;
                } else {
                    $depth[$childId] = $depth[$id] + 1;
                    $kept[] = $childId;
                }
                $queue[] = $childId;
            }
            if (isset($children[$id])) {
                $children[$id] = $kept;
            }
        }

        // 5. Materialise immutable nodes and sort siblings for display.
        $nodes = [];
        foreach ($raw as $id => $node) {
            $nodes[$id] = new AssetCategoryTreeNode(
                id: $id,
                name: $node['name'],
                storedParentId: $node['stored_parent'],
                parentId: $parent[$id],
                isAssignable: $node['assignable'],
                sortOrder: $node['sort_order'],
                depth: $depth[$id],
                detachedReason: $reason[$id] ?? null,
            );
        }

        $sort = static function (array $ids) use ($nodes): array {
            usort($ids, static function (int $a, int $b) use ($nodes): int {
                return [$nodes[$a]->sortOrder, mb_strtolower($nodes[$a]->name), $a]
                    <=> [$nodes[$b]->sortOrder, mb_strtolower($nodes[$b]->name), $b];
            });

            return $ids;
        };

        foreach ($children as $parentId => $ids) {
            $children[$parentId] = $sort($ids);
        }

        return new self($nodes, $children, $sort($roots));
    }

    public function count(): int
    {
        return count($this->nodes);
    }

    public function has(int $id): bool
    {
        return isset($this->nodes[$id]);
    }

    public function node(int $id): ?AssetCategoryTreeNode
    {
        return $this->nodes[$id] ?? null;
    }

    /** @return list<AssetCategoryTreeNode> */
    public function roots(): array
    {
        return $this->map($this->rootIds);
    }

    /** @return list<AssetCategoryTreeNode> */
    public function children(int $id): array
    {
        return $this->map($this->childIds[$id] ?? []);
    }

    public function parent(int $id): ?AssetCategoryTreeNode
    {
        $parentId = $this->nodes[$id]->parentId ?? null;

        return $parentId === null ? null : $this->nodes[$parentId];
    }

    /**
     * Ancestors from the root down to (but excluding) the node itself.
     *
     * @return list<AssetCategoryTreeNode>
     */
    public function ancestors(int $id): array
    {
        $ancestors = [];
        $current = $this->parent($id);
        while ($current !== null) {
            $ancestors[] = $current;
            $current = $this->parent($current->id);
        }

        return array_reverse($ancestors);
    }

    /**
     * Breadcrumb path from the root down to and including the node.
     *
     * @return list<AssetCategoryTreeNode>
     */
    public function path(int $id): array
    {
        if (! $this->has($id)) {
            return [];
        }

        return [...$this->ancestors($id), $this->nodes[$id]];
    }

    /** Depth with roots at 1; 0 for unknown ids. */
    public function depth(int $id): int
    {
        return $this->nodes[$id]->depth ?? 0;
    }

    /** Levels in the subtree rooted at the node (a leaf is 1); 0 if unknown. */
    public function height(int $id): int
    {
        if (! $this->has($id)) {
            return 0;
        }

        $base = $this->nodes[$id]->depth;
        $max = $base;
        foreach ($this->descendantIds($id) as $descendantId) {
            $max = max($max, $this->nodes[$descendantId]->depth);
        }

        return $max - $base + 1;
    }

    /**
     * All descendant ids (excluding the node itself) in display order.
     *
     * @return list<int>
     */
    public function descendantIds(int $id): array
    {
        $ids = [];
        $stack = array_reverse($this->childIds[$id] ?? []);
        while ($stack !== []) {
            $current = array_pop($stack);
            $ids[] = $current;
            foreach (array_reverse($this->childIds[$current] ?? []) as $childId) {
                $stack[] = $childId;
            }
        }

        return $ids;
    }

    /**
     * Assignable category ids at or below the node: [id] for a final
     * category, its assignable descendants for a navigation group, and []
     * for unknown ids. Used to expand group filters.
     *
     * @return list<int>
     */
    public function assignableIdsWithin(int $id): array
    {
        if (! $this->has($id)) {
            return [];
        }

        return array_values(array_filter(
            [$id, ...$this->descendantIds($id)],
            fn (int $candidate): bool => $this->nodes[$candidate]->isAssignable
        ));
    }

    /** @return list<int> every assignable asset category id, in display order */
    public function assignableIds(): array
    {
        return array_values(array_map(
            fn (AssetCategoryTreeNode $node): int => $node->id,
            array_filter($this->flatten(), fn (AssetCategoryTreeNode $node): bool => $node->isAssignable)
        ));
    }

    public function isAssignable(int $id): bool
    {
        return $this->nodes[$id]->isAssignable ?? false;
    }

    public function isNavigationOnly(int $id): bool
    {
        return $this->has($id) && ! $this->nodes[$id]->isAssignable;
    }

    /**
     * True when $candidateId is $id itself or one of its descendants,
     * i.e. it could not become $id's parent without creating a cycle.
     */
    public function isSelfOrDescendant(int $id, int $candidateId): bool
    {
        return $id === $candidateId || in_array($candidateId, $this->descendantIds($id), true);
    }

    /**
     * Nodes whose stored parent could not be used, keyed and sorted by id.
     *
     * @return array<int, string> id => DETACHED_* reason
     */
    public function detached(): array
    {
        $detached = [];
        foreach ($this->nodes as $id => $node) {
            if ($node->detachedReason !== null) {
                $detached[$id] = $node->detachedReason;
            }
        }
        ksort($detached);

        return $detached;
    }

    /**
     * Every node in depth-first display order (parents before children).
     *
     * @return list<AssetCategoryTreeNode>
     */
    public function flatten(): array
    {
        $ordered = [];
        foreach ($this->rootIds as $rootId) {
            $ordered[] = $this->nodes[$rootId];
            foreach ($this->descendantIds($rootId) as $descendantId) {
                $ordered[] = $this->nodes[$descendantId];
            }
        }

        return $ordered;
    }

    /**
     * @param  list<int>  $ids
     * @return list<AssetCategoryTreeNode>
     */
    private function map(array $ids): array
    {
        return array_map(fn (int $id): AssetCategoryTreeNode => $this->nodes[$id], $ids);
    }

    private static function toPositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : null;
        }

        return null;
    }
}
