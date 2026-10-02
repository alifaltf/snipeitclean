<?php

namespace Tests\Feature\Assets\CategoryPermissions;

use App\Models\Asset;
use App\Models\Group;
use App\Models\Statuslabel;
use App\Models\User;

/**
 * ERS Phase 5B2 fixture: the Phase 5B1 random-name tree and assets, plus
 * users whose category grants (any of view/create/update/delete) come only
 * from permission groups. Real enforcement: these tests never use the
 * upstream compatibility trait.
 */
trait BuildsWriteEnforcementFixture
{
    use BuildsViewEnforcementFixture;

    /** Global Snipe-IT asset permissions for every operation. */
    protected const ALL_ASSET_PERMISSIONS = [
        'assets.view' => '1',
        'assets.create' => '1',
        'assets.edit' => '1',
        'assets.delete' => '1',
    ];

    /**
     * A non-Super-User with the given global permissions and one permission
     * group per entry of $groups, each mapping category keys to operations,
     * e.g. [['leaf1' => ['view', 'update']], ['other' => ['view']]].
     *
     * @param  list<array<string, list<string>>>  $groups
     */
    protected function writer(array $groups, array $permissions = self::ALL_ASSET_PERMISSIONS, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['permissions' => json_encode($permissions)], $attributes));

        foreach ($groups as $grants) {
            $group = Group::factory()->create(['permissions' => json_encode([])]);
            foreach ($grants as $key => $operations) {
                $this->grant($group, $this->cat[$key], $operations);
            }
            $user->groups()->attach($group->id);
        }

        $this->flushPermissions();

        return $user->fresh();
    }

    protected function readyStatus(): Statuslabel
    {
        return Statuslabel::factory()->readyToDeploy()->create();
    }

    /** Database row of an asset, bypassing every scope. */
    protected function rawAsset(Asset $asset): ?object
    {
        return Asset::withoutGlobalScopes()->getQuery()->where('id', $asset->id)->first();
    }

    protected function assetCount(): int
    {
        return Asset::withoutGlobalScopes()->getQuery()->count();
    }
}
