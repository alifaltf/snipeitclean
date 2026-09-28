<?php

namespace Tests\Unit\Services;

use App\Services\AssetCategoryTree;
use App\Services\AssetCategoryTreeNode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pure (no database) tests for the in-memory tree builder. Rows are fed in
 * directly so malformed shapes that validation would normally prevent can
 * be exercised.
 */
class AssetCategoryTreeTest extends TestCase
{
    private static function row(int|string|null $id, ?string $name, int|string|null $parent = null, mixed $assignable = true, mixed $sort = 0): array
    {
        return ['id' => $id, 'name' => $name, 'parent_id' => $parent, 'is_assignable' => $assignable, 'sort_order' => $sort];
    }

    /**
     * Group(1)
     * ├── Group(2)
     * │   ├── Leaf(4)
     * │   └── Leaf(5)
     * └── Group(3)
     *     └── Group(6)
     *         └── Leaf(7)
     * Leaf(8)  (unclassified root leaf)
     */
    private function sampleTree(): AssetCategoryTree
    {
        return AssetCategoryTree::fromRows([
            self::row(1, 'Top', null, false),
            self::row(2, 'Branch A', 1, false),
            self::row(3, 'Branch B', 1, false),
            self::row(4, 'Leaf A1', 2),
            self::row(5, 'Leaf A2', 2),
            self::row(6, 'Sub B', 3, false),
            self::row(7, 'Leaf B1', 6),
            self::row(8, 'Loose Leaf', null),
        ]);
    }

    private static function ids(array $nodes): array
    {
        return array_map(fn (AssetCategoryTreeNode $node) => $node->id, $nodes);
    }

    #[Test]
    public function it_builds_an_empty_tree_from_no_rows(): void
    {
        $tree = AssetCategoryTree::fromRows([]);

        $this->assertSame(0, $tree->count());
        $this->assertSame([], $tree->roots());
        $this->assertSame([], $tree->flatten());
        $this->assertSame([], $tree->assignableIds());
        $this->assertSame([], $tree->detached());
    }

    #[Test]
    public function it_builds_roots_children_and_parents(): void
    {
        $tree = $this->sampleTree();

        $this->assertSame(8, $tree->count());
        $this->assertSame([8, 1], self::ids($tree->roots()), 'roots sort by sort_order, then name');
        $this->assertSame([2, 3], self::ids($tree->children(1)));
        $this->assertSame([4, 5], self::ids($tree->children(2)));
        $this->assertSame([], $tree->children(4));
        $this->assertSame(2, $tree->parent(4)->id);
        $this->assertNull($tree->parent(1));
        $this->assertTrue($tree->node(1)->isRoot());
        $this->assertSame([], $tree->detached());
    }

    #[Test]
    public function it_reports_ancestors_path_depth_and_height(): void
    {
        $tree = $this->sampleTree();

        $this->assertSame([1, 3, 6], self::ids($tree->ancestors(7)));
        $this->assertSame([1, 3, 6, 7], self::ids($tree->path(7)));
        $this->assertSame([], $tree->ancestors(1));
        $this->assertSame([1], self::ids($tree->path(1)));

        $this->assertSame(1, $tree->depth(1));
        $this->assertSame(4, $tree->depth(7));
        $this->assertSame(4, $tree->height(1));
        $this->assertSame(2, $tree->height(2));
        $this->assertSame(1, $tree->height(7));
    }

    #[Test]
    public function it_lists_descendants_in_display_order(): void
    {
        $tree = $this->sampleTree();

        $this->assertSame([2, 4, 5, 3, 6, 7], $tree->descendantIds(1));
        $this->assertSame([], $tree->descendantIds(7));
        $this->assertSame([8, 1, 2, 4, 5, 3, 6, 7], self::ids($tree->flatten()));
    }

    #[Test]
    public function it_expands_groups_to_their_assignable_descendants(): void
    {
        $tree = $this->sampleTree();

        $this->assertSame([4, 5, 7], $tree->assignableIdsWithin(1));
        $this->assertSame([7], $tree->assignableIdsWithin(3));
        $this->assertSame([4], $tree->assignableIdsWithin(4), 'a final category expands to itself');
        $this->assertSame([8, 4, 5, 7], $tree->assignableIds());
    }

    #[Test]
    public function it_distinguishes_navigation_nodes_from_final_categories(): void
    {
        $tree = $this->sampleTree();

        $this->assertTrue($tree->isNavigationOnly(1));
        $this->assertFalse($tree->isAssignable(1));
        $this->assertTrue($tree->isAssignable(4));
        $this->assertFalse($tree->isNavigationOnly(4));
        $this->assertTrue($tree->node(1)->isNavigationOnly());
    }

    #[Test]
    public function unknown_ids_return_empty_results_instead_of_failing(): void
    {
        $tree = $this->sampleTree();

        $this->assertFalse($tree->has(999));
        $this->assertNull($tree->node(999));
        $this->assertNull($tree->parent(999));
        $this->assertSame([], $tree->children(999));
        $this->assertSame([], $tree->ancestors(999));
        $this->assertSame([], $tree->path(999));
        $this->assertSame([], $tree->descendantIds(999));
        $this->assertSame([], $tree->assignableIdsWithin(999));
        $this->assertSame(0, $tree->depth(999));
        $this->assertSame(0, $tree->height(999));
        $this->assertFalse($tree->isAssignable(999));
        $this->assertFalse($tree->isNavigationOnly(999));
    }

    #[Test]
    public function it_detects_self_and_descendant_candidates_for_cycle_prevention(): void
    {
        $tree = $this->sampleTree();

        $this->assertTrue($tree->isSelfOrDescendant(1, 1));
        $this->assertTrue($tree->isSelfOrDescendant(1, 7));
        $this->assertTrue($tree->isSelfOrDescendant(3, 6));
        $this->assertFalse($tree->isSelfOrDescendant(3, 2));
        $this->assertFalse($tree->isSelfOrDescendant(7, 1));
    }

    #[Test]
    public function siblings_sort_by_sort_order_then_case_insensitive_name_then_id(): void
    {
        $tree = AssetCategoryTree::fromRows([
            self::row(1, 'Group', null, false),
            self::row(2, 'bravo', 1, true, 0),
            self::row(3, 'Alpha', 1, true, 0),
            self::row(4, 'Zulu', 1, true, 0),
            self::row(5, 'Last by order', 1, true, 5),
            self::row(6, 'First by order', 1, true, 0),
            self::row(7, 'alpha', 1, true, 0),
        ]);

        $this->assertSame([3, 7, 2, 6, 4, 5], self::ids($tree->children(1)));
    }

    #[Test]
    public function it_detaches_nodes_whose_parent_is_missing(): void
    {
        // Parent 50 is absent: never existed, soft-deleted or not an asset category.
        $tree = AssetCategoryTree::fromRows([
            self::row(1, 'Orphan Group', 50, false),
            self::row(2, 'Child', 1),
        ]);

        $this->assertSame([1 => AssetCategoryTree::DETACHED_MISSING_PARENT], $tree->detached());
        $this->assertNull($tree->node(1)->parentId);
        $this->assertSame(50, $tree->node(1)->storedParentId);
        $this->assertTrue($tree->node(1)->isDetached());
        $this->assertSame([1], self::ids($tree->roots()));
        $this->assertSame([2], self::ids($tree->children(1)), 'the orphan keeps its own subtree');
    }

    #[Test]
    public function it_detaches_self_parented_nodes(): void
    {
        $tree = AssetCategoryTree::fromRows([self::row(1, 'Loop', 1, false)]);

        $this->assertSame([1 => AssetCategoryTree::DETACHED_SELF_PARENT], $tree->detached());
        $this->assertSame([1], self::ids($tree->roots()));
    }

    #[Test]
    public function it_detaches_children_of_final_categories(): void
    {
        $tree = AssetCategoryTree::fromRows([
            self::row(1, 'Final', null, true),
            self::row(2, 'Should not be here', 1),
        ]);

        $this->assertSame([2 => AssetCategoryTree::DETACHED_PARENT_NOT_NAVIGATION], $tree->detached());
        $this->assertSame([], $tree->children(1));
        $this->assertSame([1, 2], self::ids($tree->roots()));
        $this->assertSame([1], $tree->assignableIdsWithin(1));
    }

    #[Test]
    public function it_breaks_a_two_node_cycle(): void
    {
        $tree = AssetCategoryTree::fromRows([
            self::row(1, 'A', 2, false),
            self::row(2, 'B', 1, false),
        ]);

        $this->assertSame(
            [1 => AssetCategoryTree::DETACHED_CYCLE, 2 => AssetCategoryTree::DETACHED_CYCLE],
            $tree->detached()
        );
        $this->assertSame([1, 2], self::ids($tree->roots()));
        $this->assertSame([], $tree->descendantIds(1));
    }

    #[Test]
    public function it_breaks_a_longer_cycle_and_keeps_nodes_hanging_below_it(): void
    {
        $tree = AssetCategoryTree::fromRows([
            self::row(1, 'A', 3, false),
            self::row(2, 'B', 1, false),
            self::row(3, 'C', 2, false),
            self::row(4, 'Below B', 2),
            self::row(5, 'Healthy Root', null, false),
        ]);

        $detached = $tree->detached();
        ksort($detached);
        $this->assertSame([
            1 => AssetCategoryTree::DETACHED_CYCLE,
            2 => AssetCategoryTree::DETACHED_CYCLE,
            3 => AssetCategoryTree::DETACHED_CYCLE,
        ], $detached);
        $this->assertSame([4], self::ids($tree->children(2)));
        $this->assertSame(2, $tree->parent(4)->id);
        $this->assertNull($tree->node(4)->detachedReason);
        $this->assertSame([1, 2, 3, 5], self::ids($tree->roots()));
        $this->assertSame([1, 2, 4, 3, 5], self::ids($tree->flatten()), 'every node is reachable exactly once');
    }

    #[Test]
    public function it_detaches_nodes_deeper_than_the_maximum_depth(): void
    {
        $rows = [self::row(1, 'Level 1', null, false)];
        for ($level = 2; $level <= AssetCategoryTree::MAX_DEPTH + 2; $level++) {
            $rows[] = self::row($level, "Level $level", $level - 1, $level === AssetCategoryTree::MAX_DEPTH + 2);
        }

        $tree = AssetCategoryTree::fromRows($rows);
        $tooDeep = AssetCategoryTree::MAX_DEPTH + 1;

        $this->assertSame(AssetCategoryTree::MAX_DEPTH, $tree->depth(AssetCategoryTree::MAX_DEPTH));
        $this->assertSame([$tooDeep => AssetCategoryTree::DETACHED_MAX_DEPTH], $tree->detached());
        $this->assertSame(1, $tree->depth($tooDeep));
        $this->assertSame(2, $tree->depth($tooDeep + 1));
        $this->assertSame([], $tree->children(AssetCategoryTree::MAX_DEPTH));
        $this->assertSame(AssetCategoryTree::MAX_DEPTH, $tree->height(1));
    }

    #[Test]
    public function it_normalises_database_style_values(): void
    {
        // SQLite / MySQL may return numeric strings; NULL flags use the column default.
        $tree = AssetCategoryTree::fromRows([
            self::row('1', 'Group', null, '0', '3'),
            self::row('2', 'Leaf', '1', '1', null),
            self::row('3', 'Default flag', '1', null, 'not-a-number'),
            self::row('4', 'Negative sort', '1', 1, -5),
        ]);

        $this->assertTrue($tree->isNavigationOnly(1));
        $this->assertSame(3, $tree->node(1)->sortOrder);
        $this->assertTrue($tree->isAssignable(2));
        $this->assertTrue($tree->isAssignable(3));
        $this->assertSame(0, $tree->node(3)->sortOrder);
        $this->assertSame(0, $tree->node(4)->sortOrder);
        $this->assertSame([3, 2, 4], $tree->descendantIds(1), 'equal sort_order falls back to name');
    }

    #[Test]
    public function it_ignores_invalid_and_duplicate_ids(): void
    {
        $tree = AssetCategoryTree::fromRows([
            self::row(null, 'No id'),
            self::row(0, 'Zero id'),
            self::row(-3, 'Negative id'),
            self::row('abc', 'Text id'),
            self::row(1, 'First'),
            self::row(1, 'Duplicate'),
            (object) ['id' => 2, 'name' => 'Object row', 'parent_id' => '0', 'is_assignable' => true, 'sort_order' => 0],
        ]);

        $this->assertSame(2, $tree->count());
        $this->assertSame('First', $tree->node(1)->name);
        $this->assertNull($tree->node(2)->storedParentId, 'parent_id 0 is treated as no parent');
        $this->assertSame([], $tree->detached());
    }
}
