<?php

namespace Tests\Feature\AssetModels\CategoryAssignment;

use App\Models\AssetModel;
use App\Models\Category;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Categories\Hierarchy\CreatesCategoryHierarchy;
use Tests\TestCase;

class AssetModelCategoryApiTest extends TestCase
{
    use CreatesCategoryHierarchy;

    #[Test]
    public function api_create_rejects_a_group_and_accepts_a_final_category(): void
    {
        $user = $this->superUser();
        $group = $this->group('Hardware');
        $laptop = $this->finalCategory('Laptop', $group);

        $this->actingAsForApi($user)
            ->postJson(route('api.models.store'), ['name' => 'Group Model', 'category_id' => $group->id])
            ->assertOk()
            ->assertStatusMessageIs('error')
            ->assertJsonPath('messages.category_id.0', trans('admin/models/message.category_rule.navigation'));
        $this->assertFalse(AssetModel::where('name', 'Group Model')->exists());

        $this->actingAsForApi($user)
            ->postJson(route('api.models.store'), ['name' => 'Laptop Model', 'category_id' => $laptop->id])
            ->assertOk()
            ->assertStatusMessageIs('success')
            ->assertJsonPath('payload.category.id', $laptop->id);
    }

    #[Test]
    public function api_create_rejects_deleted_and_missing_categories(): void
    {
        $deleted = $this->finalCategory('Retired');
        $deleted->delete();

        foreach ([[$deleted->id, 'deleted'], [424242, 'missing']] as [$categoryId, $problem]) {
            $this->actingAsForApi($this->superUser())
                ->postJson(route('api.models.store'), ['name' => 'Model '.$problem, 'category_id' => $categoryId])
                ->assertOk()
                ->assertStatusMessageIs('error')
                ->assertJsonPath('messages.category_id.0', trans('admin/models/message.category_rule.'.$problem));
        }

        $this->assertSame(0, AssetModel::where('name', 'like', 'Model %')->count());
    }

    #[Test]
    public function api_update_rejects_a_group_and_accepts_a_final_category(): void
    {
        $user = $this->superUser();
        $group = $this->group('Hardware');
        $laptop = $this->finalCategory('Laptop', $group);
        $desktop = $this->finalCategory('Desktop', $group);
        $model = AssetModel::factory()->create(['name' => 'Before', 'category_id' => $laptop->id]);

        $this->actingAsForApi($user)
            ->patchJson(route('api.models.update', $model), ['name' => 'After', 'category_id' => $group->id])
            ->assertOk()
            ->assertStatusMessageIs('error')
            ->assertJsonPath('messages.category_id.0', trans('admin/models/message.category_rule.navigation'));
        $this->assertDatabaseHas('models', ['id' => $model->id, 'name' => 'Before', 'category_id' => $laptop->id]);

        $this->actingAsForApi($user)
            ->patchJson(route('api.models.update', $model), ['category_id' => $desktop->id])
            ->assertOk()
            ->assertStatusMessageIs('success');
        $this->assertSame($desktop->id, $model->fresh()->category_id);
    }

    #[Test]
    public function api_update_without_a_category_is_unchanged(): void
    {
        $laptop = $this->finalCategory('Laptop', $this->group('Hardware'));
        $model = AssetModel::factory()->create(['category_id' => $laptop->id]);

        $this->actingAsForApi($this->superUser())
            ->patchJson(route('api.models.update', $model), ['notes' => 'Only notes'])
            ->assertOk()
            ->assertStatusMessageIs('success');

        $this->assertSame('Only notes', $model->fresh()->notes);
    }

    // ---------------------------------------------------------------
    // Category select lists
    // ---------------------------------------------------------------

    private function selectlistIds(string $type): array
    {
        return collect($this->actingAsForApi($this->ordinaryAdmin())
            ->getJson(route('api.categories.selectlist', ['item_type' => $type]))
            ->assertOk()
            ->json('results'))
            ->pluck('id')
            ->all();
    }

    #[Test]
    public function asset_category_select_lists_exclude_navigation_groups(): void
    {
        $fixed = $this->group('Fixed');
        $hardware = $this->group('Hardware', $fixed);
        $laptop = $this->finalCategory('Laptop', $hardware);
        $loose = $this->finalCategory('Unclassified');
        $deleted = $this->finalCategory('Retired');
        $deleted->delete();

        $ids = $this->selectlistIds('asset');

        $this->assertEqualsCanonicalizing([$laptop->id, $loose->id], $ids);
        $this->assertNotContains($fixed->id, $ids);
        $this->assertNotContains($hardware->id, $ids);
    }

    #[Test]
    public function non_asset_select_lists_are_unchanged(): void
    {
        foreach (['accessory' => 'forAccessories', 'consumable' => 'forConsumables', 'component' => 'forComponents', 'license' => 'forLicenses'] as $type => $state) {
            $normal = Category::factory()->{$state}()->create(['name' => "Normal {$type}"]);
            // A (malformed) non-asset row carrying a navigation flag is still
            // listed: the flag has no meaning outside asset categories.
            $flagged = Category::factory()->{$state}()->create(['name' => "Flagged {$type}", 'is_assignable' => false]);

            $ids = $this->selectlistIds($type);
            $this->assertContains($normal->id, $ids, $type);
            $this->assertContains($flagged->id, $ids, $type);
        }
    }

    #[Test]
    public function asset_model_forms_use_the_filtered_asset_select_list(): void
    {
        $model = AssetModel::factory()->create(['category_id' => $this->finalCategory('Laptop')->id]);
        $user = $this->superUser();

        foreach ([route('models.create'), route('models.edit', $model), route('models.clone.create', $model)] as $url) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('data-endpoint="categories/asset"', false);
        }

        $this->actingAs($user)
            ->post(route('models.bulkedit.index'), ['ids' => [$model->id]])
            ->assertOk()
            ->assertSee('categories/asset', false);
    }
}
