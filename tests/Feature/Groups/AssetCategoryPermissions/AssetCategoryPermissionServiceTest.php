<?php

namespace Tests\Feature\Groups\AssetCategoryPermissions;

use App\Models\Category;
use App\Models\Group;
use App\Models\User;
use App\Services\AssetCategoryAccess;
use App\Services\AssetCategoryPermissionService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Effective permission resolution (ERS Phase 5A). Categories use random
 * names; nothing depends on specific names or ids.
 */
class AssetCategoryPermissionServiceTest extends TestCase
{
    use BuildsPermissionFixture;

    private function access(User $user): AssetCategoryAccess
    {
        $service = app(AssetCategoryPermissionService::class);
        $service->flush();

        return $service->forUser($user->fresh());
    }

    #[Test]
    public function super_admins_may_do_everything_on_every_live_final_category(): void
    {
        $this->buildTree();
        $access = $this->access(User::factory()->superuser()->create());

        $this->assertTrue($access->isSuperAdmin());
        foreach (AssetCategoryAccess::OPERATIONS as $operation) {
            $this->assertEqualsCanonicalizing($this->finalIds(), $access->categoryIds($operation), $operation);
        }
        // Navigation groups and non-final ids are never "categories" to act on.
        $this->assertFalse($access->allows('view', $this->cat['groupA']->id));
        $this->assertTrue($access->allowsWithin('view', $this->cat['groupA']->id));
        $this->assertFalse($access->allows('view', $this->cat['accessory']->id));
        $this->assertFalse($access->allows('view', $this->cat['deleted']->id));
    }

    #[Test]
    public function ordinary_users_and_admins_are_denied_by_default(): void
    {
        $this->buildTree();

        foreach ([
            'asset manager' => $this->userWithAssetPermissions(),
            'ordinary admin' => User::factory()->admin()->create(),
            'user with no permissions' => User::factory()->create(),
        ] as $label => $user) {
            $access = $this->access($user);
            $this->assertFalse($access->isSuperAdmin(), $label);
            foreach (AssetCategoryAccess::OPERATIONS as $operation) {
                $this->assertSame([], $access->categoryIds($operation), "{$label} {$operation}");
                $this->assertFalse($access->allows($operation, $this->cat['leaf1']->id), "{$label} {$operation}");
                $this->assertFalse($access->allowsWithin($operation, $this->cat['groupA']->id), "{$label} {$operation}");
            }
        }

        $guest = app(AssetCategoryPermissionService::class)->forUser(null);
        $this->assertSame([], $guest->categoryIds('view'));
    }

    #[Test]
    public function grants_from_all_of_a_users_groups_are_combined(): void
    {
        $this->buildTree();
        $groupOne = $this->groupWithAssetPermissions();
        $groupTwo = $this->groupWithAssetPermissions();
        $this->grant($groupOne, $this->cat['leaf1'], ['view']);
        $this->grant($groupTwo, $this->cat['leaf1'], ['update']);
        $this->grant($groupTwo, $this->cat['other'], ['view', 'delete']);

        $user = User::factory()->create();
        $user->groups()->attach([$groupOne->id, $groupTwo->id]);
        $access = $this->access($user);

        $this->assertEqualsCanonicalizing([$this->cat['leaf1']->id, $this->cat['other']->id], $access->categoryIds('view'));
        $this->assertSame([$this->cat['leaf1']->id], $access->categoryIds('update'));
        $this->assertSame([$this->cat['other']->id], $access->categoryIds('delete'));
        $this->assertSame([], $access->categoryIds('create'));

        // A member of only one group gets only that group's grants.
        $onlyOne = User::factory()->create();
        $onlyOne->groups()->attach($groupOne->id);
        $this->assertSame([$this->cat['leaf1']->id], $this->access($onlyOne)->categoryIds('view'));
        $this->assertSame([], $this->access($onlyOne)->categoryIds('update'));
    }

    #[Test]
    public function operations_are_independent(): void
    {
        $this->buildTree();

        foreach (AssetCategoryAccess::OPERATIONS as $only) {
            $group = $this->groupWithAssetPermissions();
            $this->grant($group, $this->cat['leaf2'], [$only]);
            $user = User::factory()->create();
            $user->groups()->attach($group->id);
            $access = $this->access($user);

            foreach (AssetCategoryAccess::OPERATIONS as $operation) {
                $this->assertSame($operation === $only, $access->allows($operation, $this->cat['leaf2']->id), "{$only} grant, {$operation} check");
            }
        }
    }

    #[Test]
    public function the_global_asset_permission_is_an_upper_bound(): void
    {
        $this->buildTree();

        // The group grants every operation on leaf1, but globally only
        // allows viewing assets.
        $group = Group::factory()->create(['permissions' => json_encode(['assets.view' => '1'])]);
        $this->grant($group, $this->cat['leaf1'], AssetCategoryAccess::OPERATIONS);
        $user = User::factory()->create();
        $user->groups()->attach($group->id);

        $access = $this->access($user);
        $this->assertTrue($access->allows('view', $this->cat['leaf1']->id));
        foreach (['create', 'update', 'delete'] as $operation) {
            $this->assertFalse($access->allows($operation, $this->cat['leaf1']->id), $operation);
        }

        // A personal explicit deny of assets.view also caps the category grant.
        $user->permissions = json_encode(['assets.view' => '-1']);
        $user->save();
        $this->assertFalse($this->access($user)->allows('view', $this->cat['leaf1']->id));

        // A user with no global asset permissions gets nothing from grants.
        $bare = Group::factory()->create(['permissions' => json_encode([])]);
        $this->grant($bare, $this->cat['leaf1'], AssetCategoryAccess::OPERATIONS);
        $member = User::factory()->create();
        $member->groups()->attach($bare->id);
        $this->assertSame([], $this->access($member)->categoryIds('view'));
    }

    #[Test]
    public function new_categories_are_denied_until_granted(): void
    {
        $this->buildTree();
        $group = $this->groupWithAssetPermissions();
        $this->grant($group, $this->cat['leaf1'], AssetCategoryAccess::OPERATIONS);
        $user = User::factory()->create();
        $user->groups()->attach($group->id);

        $new = $this->finalCategory($this->randomName('Brand new'), $this->cat['branch']);
        $access = $this->access($user);

        $this->assertFalse($access->allows('view', $new->id));
        // ...even though its parent group has other granted categories.
        $this->assertSame([$this->cat['leaf1']->id], $access->categoryIdsWithin('view', $this->cat['branch']->id));

        $this->grant($group, $new, ['view']);
        $this->assertTrue($this->access($user)->allows('view', $new->id));

        // Super Admins see new categories immediately.
        $this->assertTrue($this->access(User::factory()->superuser()->create())->allows('delete', $new->id));
    }

    #[Test]
    public function navigation_group_access_is_derived_from_granted_descendants(): void
    {
        $this->buildTree();
        $group = $this->groupWithAssetPermissions();
        $this->grant($group, $this->cat['leaf2'], ['view']);
        $user = User::factory()->create();
        $user->groups()->attach($group->id);
        $access = $this->access($user);

        $this->assertTrue($access->allowsWithin('view', $this->cat['groupA']->id));
        $this->assertTrue($access->allowsWithin('view', $this->cat['branch']->id));
        $this->assertSame([$this->cat['leaf2']->id], $access->categoryIdsWithin('view', $this->cat['groupA']->id));
        $this->assertFalse($access->allowsWithin('view', $this->cat['groupB']->id));
        $this->assertFalse($access->allowsWithin('update', $this->cat['groupA']->id));
        $this->assertFalse($access->allowsWithin('view', $this->cat['emptyGroup']->id));
        // A final category "within" itself.
        $this->assertTrue($access->allowsWithin('view', $this->cat['leaf2']->id));
    }

    #[Test]
    public function stale_rows_on_non_final_categories_are_ignored(): void
    {
        $this->buildTree();
        $group = $this->groupWithAssetPermissions();
        $user = User::factory()->create();
        $user->groups()->attach($group->id);

        // Rows written directly (bypassing the save action) against a
        // navigation group, a deleted category and a non-asset category.
        foreach (['groupA', 'deleted', 'accessory'] as $key) {
            DB::table('asset_category_permissions')->insert([
                'group_id' => $group->id, 'category_id' => $this->cat[$key]->id,
                'can_view' => true, 'can_create' => true, 'can_update' => true, 'can_delete' => true,
            ]);
        }

        $access = $this->access($user);
        foreach (AssetCategoryAccess::OPERATIONS as $operation) {
            $this->assertSame([], $access->categoryIds($operation), $operation);
        }
    }

    #[Test]
    public function grants_are_loaded_with_a_constant_number_of_queries(): void
    {
        $this->buildTree();
        $group = $this->groupWithAssetPermissions();
        foreach ([$this->cat['leaf1'], $this->cat['leaf2'], $this->cat['direct'], $this->cat['other']] as $category) {
            $this->grant($group, $category, ['view']);
        }
        $user = User::factory()->create();
        $user->groups()->attach($group->id);
        $user = $user->fresh();

        $service = app(AssetCategoryPermissionService::class);
        $service->flush();

        DB::enableQueryLog();
        $first = $service->forUser($user);
        $firstCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        $second = $service->forUser($user);
        $secondCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($first, $second, 'cached for the request');
        $this->assertSame(0, $secondCount);
        // Tree + grants + the user's groups for the global permission check;
        // independent of how many categories or grants exist.
        $this->assertLessThanOrEqual(4, $firstCount);
        $this->assertCount(4, $first->categoryIds('view'));
    }

    #[Test]
    public function unknown_operations_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(AssetCategoryPermissionService::class)->forUser(null)->allows('checkout', 1);
    }

    #[Test]
    public function the_service_holds_no_hardcoded_category_names_or_ids(): void
    {
        $doc = file_get_contents(base_path('docs/ERS_REQUIREMENTS.md'));
        $section = substr($doc, strpos($doc, 'Initial structure:'));
        $section = substr($section, 0, strpos($section, 'Parent levels are navigation groups'));
        preg_match_all('/^\s*-\s+(.+?)\s*$/m', $section, $matches);
        $this->assertContains('Laptop', $matches[1]);

        foreach ([
            'app/Services/AssetCategoryAccess.php',
            'app/Services/AssetCategoryPermissionService.php',
            'app/Actions/Groups/SaveGroupAssetCategoryPermissionsAction.php',
            'app/Models/AssetCategoryPermission.php',
            'app/Http/Controllers/GroupsController.php',
            'resources/views/groups/partials/asset-category-permissions.blade.php',
            'database/migrations/2026_09_28_000000_create_asset_category_permissions_table.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));
            foreach ($matches[1] as $name) {
                $this->assertDoesNotMatchRegularExpression('/\b'.preg_quote($name, '/').'\b/', $source, "{$path} mentions {$name}");
            }
            $this->assertDoesNotMatchRegularExpression('/category_id[\'"]?\s*(=>|=|==)\s*\d/', $source, $path);
        }
    }
}
