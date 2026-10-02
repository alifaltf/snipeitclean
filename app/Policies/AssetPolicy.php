<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\Company;
use App\Models\User;
use App\Services\AssetCategoryAccess;
use App\Services\AssetCategoryWriteAuthorizer;
use Illuminate\Support\Facades\Gate;

class AssetPolicy extends CheckoutablePermissionsPolicy
{
    /**
     * ERS Phase 5B2: abilities for the actual asset-RECORD write operations
     * (and the buttons and table actions that offer them). Each one is the
     * existing upstream asset permission PLUS an asset-category grant on the
     * asset's current final category:
     *
     *   editRecord    = 'update' on the asset + category Edit
     *                   (edit form, update, bulk edit)
     *   deleteRecord  = 'delete' on the asset + category Delete
     *                   (delete, bulk delete)
     *   restoreRecord = global assets.delete + company scope + category
     *                   Delete (single, bulk and API restore, and the Restore
     *                   actions). Snipe-IT treats restore as delete-level;
     *                   the asset-level 'delete' check is not reused because
     *                   it refuses items that are already deleted.
     *
     * Cloning is decided by AssetCategoryWriteAuthorizer::canClone(), which
     * also validates the clone target for Super Admins.
     *
     * The plain 'update' / 'delete' / 'create' abilities are deliberately
     * left exactly as upstream, because notes, maintenances, files and other
     * out-of-scope features also use them.
     */
    private const RECORD_ABILITIES = [
        'editRecord' => AssetCategoryAccess::UPDATE,
        'deleteRecord' => AssetCategoryAccess::DELETE,
        'restoreRecord' => AssetCategoryAccess::DELETE,
    ];

    /**
     * Super Users never reach this (the global Gate::before lets them
     * through first). Record abilities are decided completely by the methods
     * below, skipping the parent's "admin may do everything" shortcut, so
     * ordinary Admins are category-restricted too. Every other ability is
     * unchanged.
     */
    public function before(User $user, $ability, $item)
    {
        if (isset(self::RECORD_ABILITIES[$ability])) {
            return null;
        }

        return parent::before($user, $ability, $item);
    }

    public function editRecord(User $user, $item = null): bool
    {
        return $item instanceof Asset && $item->exists
            && Gate::forUser($user)->allows('update', $item)
            && $this->categoryAllows($user, 'editRecord', $item);
    }

    public function deleteRecord(User $user, $item = null): bool
    {
        return $item instanceof Asset && $item->exists
            && Gate::forUser($user)->allows('delete', $item)
            && $this->categoryAllows($user, 'deleteRecord', $item);
    }

    public function restoreRecord(User $user, $item = null): bool
    {
        return $item instanceof Asset && $item->exists
            && Gate::forUser($user)->allows('delete', Asset::class)
            && Company::isCurrentUserHasAccess($item)
            && $this->categoryAllows($user, 'restoreRecord', $item);
    }

    private function categoryAllows(User $user, string $ability, Asset $asset): bool
    {
        return app(AssetCategoryWriteAuthorizer::class)->allowsAsset(self::RECORD_ABILITIES[$ability], $asset, $user);
    }

    protected function columnName()
    {
        return 'assets';
    }

    public function viewRequestable(User $user, ?Asset $asset = null)
    {
        return $user->hasAccess('assets.view.requestable');
    }

    public function audit(User $user, ?Asset $asset = null)
    {
        return $user->hasAccess('assets.audit');
    }

    public function files(User $user, $item = null)
    {
        return $user->hasAccess($this->columnName().'.files');
    }
}
