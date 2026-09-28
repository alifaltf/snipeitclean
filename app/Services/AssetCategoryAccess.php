<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * ERS Phase 5A: one user's EFFECTIVE asset-category permissions.
 *
 * Immutable and built by AssetCategoryPermissionService, which loads every
 * grant for the user in one query. Rules:
 *
 *  - Super Admin (Snipe-IT "superuser") may do everything on every live
 *    final asset category;
 *  - everyone else gets the UNION of the grants of all their permission
 *    groups, and nothing without a grant (default deny);
 *  - the matching global Snipe-IT asset permission is an upper bound: a
 *    category grant never adds an operation the user lacks globally;
 *  - only live final/assignable asset categories can be authorised;
 *    navigation groups are authorised only through their descendants.
 *
 * Operations are independent: view, create, update, delete.
 *
 * Not enforced anywhere yet (Phase 5B).
 */
final class AssetCategoryAccess
{
    public const VIEW = 'view';

    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const DELETE = 'delete';

    public const OPERATIONS = [self::VIEW, self::CREATE, self::UPDATE, self::DELETE];

    /** @var array<string, list<int>> operation => authorised final ids, tree order */
    private array $authorised = [];

    /** @var array<string, array<int, true>> operation => set of authorised ids */
    private array $authorisedSet = [];

    /**
     * @param  array<int, array<string, bool>>  $grants  category id => operation => granted (union across groups)
     * @param  array<string, bool>  $globalAllowed  operation => global Snipe-IT asset permission
     */
    public function __construct(
        private readonly AssetCategoryTree $tree,
        private readonly bool $superAdmin,
        array $grants,
        private readonly array $globalAllowed,
    ) {
        foreach (self::OPERATIONS as $operation) {
            $ids = [];
            if ($this->globalAllowed[$operation] ?? false) {
                foreach ($tree->assignableIds() as $id) {
                    if ($superAdmin || ($grants[$id][$operation] ?? false)) {
                        $ids[] = $id;
                    }
                }
            }
            $this->authorised[$operation] = $ids;
            $this->authorisedSet[$operation] = array_fill_keys($ids, true);
        }
    }

    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    /** May the user perform $operation on this final asset category? */
    public function allows(string $operation, int $categoryId): bool
    {
        self::assertOperation($operation);

        return isset($this->authorisedSet[$operation][$categoryId]);
    }

    /**
     * Authorised final asset category ids for an operation, in tree order.
     *
     * @return list<int>
     */
    public function categoryIds(string $operation): array
    {
        self::assertOperation($operation);

        return $this->authorised[$operation];
    }

    /**
     * Authorised final categories at or below a node (a final category
     * itself, or the descendants of a navigation group).
     *
     * @return list<int>
     */
    public function categoryIdsWithin(string $operation, int $nodeId): array
    {
        self::assertOperation($operation);

        return array_values(array_filter(
            $this->tree->assignableIdsWithin($nodeId),
            fn (int $id): bool => isset($this->authorisedSet[$operation][$id])
        ));
    }

    /**
     * True when the node is authorised for the operation: a final category
     * that is granted, or a navigation group with at least one granted
     * final descendant.
     */
    public function allowsWithin(string $operation, int $nodeId): bool
    {
        return $this->categoryIdsWithin($operation, $nodeId) !== [];
    }

    public static function assertOperation(string $operation): void
    {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new InvalidArgumentException("Unknown asset category operation [{$operation}].");
        }
    }

    /** Database column holding an operation's flag. */
    public static function column(string $operation): string
    {
        self::assertOperation($operation);

        return 'can_'.$operation;
    }
}
