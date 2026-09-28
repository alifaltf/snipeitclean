<?php

namespace Tests\Feature\Categories\Hierarchy;

use App\Models\Category;
use App\Models\Group;
use App\Models\User;

/**
 * Test data helpers for the ERS asset category hierarchy. Names are test
 * data only; application code never references them.
 */
trait CreatesCategoryHierarchy
{
    protected function superUser(): User
    {
        return User::factory()->superuser()->create();
    }

    protected function superUserViaGroup(): User
    {
        $group = Group::create(['name' => 'Hierarchy Super Users', 'permissions' => json_encode(['superuser' => '1'])]);
        $user = User::factory()->create();
        $user->groups()->attach($group->id);

        return $user->fresh();
    }

    protected function ordinaryAdmin(): User
    {
        return User::factory()->admin()->create();
    }

    /** Can view/create/edit/delete categories, but is not a Super User. */
    protected function categoryManager(): User
    {
        return User::factory()->create([
            'permissions' => json_encode([
                'categories.view' => '1',
                'categories.create' => '1',
                'categories.edit' => '1',
                'categories.delete' => '1',
            ]),
        ]);
    }

    protected function group(string $name, ?Category $parent = null, int $sortOrder = 0): Category
    {
        $factory = Category::factory()->navigationGroup();
        if ($parent) {
            $factory = $factory->childOf($parent);
        }

        return $factory->create(['name' => $name, 'sort_order' => $sortOrder]);
    }

    protected function finalCategory(string $name, ?Category $parent = null, int $sortOrder = 0): Category
    {
        $factory = Category::factory()->assignableAssetCategory();
        if ($parent) {
            $factory = $factory->childOf($parent);
        }

        return $factory->create(['name' => $name, 'sort_order' => $sortOrder]);
    }

    /**
     * A straight chain of navigation groups, depth 1..$levels.
     *
     * @return list<Category> index 0 is the root
     */
    protected function groupChain(int $levels, string $prefix = 'Level'): array
    {
        $chain = [];
        $parent = null;
        for ($i = 1; $i <= $levels; $i++) {
            $parent = $this->group($prefix.' '.$i, $parent);
            $chain[] = $parent;
        }

        return $chain;
    }
}
