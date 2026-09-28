<?php

namespace App\Services;

/**
 * Immutable, read-only view of one asset category inside an AssetCategoryTree.
 *
 * $parentId is the EFFECTIVE parent used for navigation. It differs from
 * $storedParentId when the stored pointer is unusable (missing, soft-deleted,
 * non-asset or non-navigation parent, self-reference, cycle, too deep); in
 * that case the node is shown at the root and $detachedReason says why.
 */
final class AssetCategoryTreeNode
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $storedParentId,
        public readonly ?int $parentId,
        public readonly bool $isAssignable,
        public readonly int $sortOrder,
        public readonly int $depth,
        public readonly ?string $detachedReason = null,
    ) {}

    public function isNavigationOnly(): bool
    {
        return ! $this->isAssignable;
    }

    public function isRoot(): bool
    {
        return $this->parentId === null;
    }

    public function isDetached(): bool
    {
        return $this->detachedReason !== null;
    }
}
