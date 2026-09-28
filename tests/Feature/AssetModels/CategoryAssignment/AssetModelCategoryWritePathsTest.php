<?php

namespace Tests\Feature\AssetModels\CategoryAssignment;

use App\Http\Controllers\AssetModelsController;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Manufacturer;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Categories\Hierarchy\CreatesCategoryHierarchy;
use Tests\TestCase;

/**
 * Every Asset Model category write path enforces "live, final/assignable
 * asset category" (ERS Phase 3): model validation, web create/update,
 * clone, restore and both raw bulk-edit paths.
 */
class AssetModelCategoryWritePathsTest extends TestCase
{
    use CreatesCategoryHierarchy;

    private function navigationMessage(): string
    {
        return trans('admin/models/message.category_rule.navigation');
    }

    // ---------------------------------------------------------------
    // Model validation (covers every Eloquent path)
    // ---------------------------------------------------------------

    #[Test]
    public function model_validation_refuses_a_navigation_group(): void
    {
        $group = $this->group('Hardware');

        $model = AssetModel::factory()->make(['name' => 'Direct', 'category_id' => $group->id]);

        $this->assertFalse($model->save());
        $this->assertSame([$this->navigationMessage()], $model->getErrors()->get('category_id'));
        $this->assertFalse(AssetModel::where('name', 'Direct')->exists());
        $this->assertSame(0, AssetModel::withTrashed()->where('category_id', $group->id)->count());
    }

    #[Test]
    public function model_validation_refuses_moving_an_existing_model_into_a_group(): void
    {
        $laptop = $this->finalCategory('Laptop');
        $group = $this->group('Hardware');
        $model = AssetModel::factory()->create(['category_id' => $laptop->id]);

        $this->assertFalse($model->update(['category_id' => $group->id]));
        $this->assertSame($laptop->id, $model->fresh()->category_id);
    }

    #[Test]
    public function model_validation_refuses_deleted_non_asset_and_missing_categories(): void
    {
        $deleted = $this->finalCategory('Retired');
        $deleted->delete();
        $accessoryCategory = Category::factory()->forAccessories()->create();

        foreach ([
            [$deleted->id, 'deleted'],
            [$accessoryCategory->id, 'not_asset'],
            [999999, 'missing'],
            ['abc', 'invalid'],
        ] as [$categoryId, $problem]) {
            $model = AssetModel::factory()->make(['category_id' => $categoryId]);
            $this->assertFalse($model->save(), $problem);
            $this->assertSame([trans('admin/models/message.category_rule.'.$problem)], $model->getErrors()->get('category_id'), $problem);
        }
    }

    #[Test]
    public function model_validation_accepts_a_final_category_inside_the_hierarchy(): void
    {
        $laptop = $this->finalCategory('Laptop', $this->group('Hardware', $this->group('Fixed')));

        $model = AssetModel::factory()->make(['category_id' => $laptop->id]);

        $this->assertTrue($model->save());
        $this->assertSame($laptop->id, $model->fresh()->category_id);
    }

    // ---------------------------------------------------------------
    // Web create / update / clone
    // ---------------------------------------------------------------

    #[Test]
    public function web_create_rejects_a_group_with_old_input_and_accepts_a_final_category(): void
    {
        $user = $this->superUser();
        $group = $this->group('Hardware');
        $laptop = $this->finalCategory('Laptop', $group);

        $this->actingAs($user)
            ->from(route('models.create'))
            ->post(route('models.store'), ['name' => 'Group Model', 'category_id' => $group->id])
            ->assertRedirect(route('models.create'))
            ->assertSessionHasErrors(['category_id' => $this->navigationMessage()])
            ->assertSessionHasInput('name', 'Group Model')
            ->assertSessionHasInput('category_id', $group->id);
        $this->assertFalse(AssetModel::where('name', 'Group Model')->exists());

        $this->actingAs($user)
            ->from(route('models.create'))
            ->post(route('models.store'), ['name' => 'Laptop Model', 'category_id' => $laptop->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('models.index'));
        $this->assertSame($laptop->id, AssetModel::where('name', 'Laptop Model')->sole()->category_id);
    }

    #[Test]
    public function web_update_rejects_a_group_and_accepts_a_final_category(): void
    {
        $user = $this->superUser();
        $group = $this->group('Hardware');
        $laptop = $this->finalCategory('Laptop', $group);
        $desktop = $this->finalCategory('Desktop', $group);
        $model = AssetModel::factory()->create(['name' => 'Before', 'category_id' => $laptop->id]);

        $this->actingAs($user)
            ->from(route('models.edit', $model))
            ->put(route('models.update', $model), ['name' => 'After', 'category_id' => $group->id])
            ->assertRedirect(route('models.edit', $model))
            ->assertSessionHasErrors(['category_id' => $this->navigationMessage()])
            ->assertSessionHasInput('name', 'After');
        $this->assertDatabaseHas('models', ['id' => $model->id, 'name' => 'Before', 'category_id' => $laptop->id]);

        $this->actingAs($user)
            ->put(route('models.update', $model), ['name' => 'After', 'category_id' => $desktop->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('models.index'));
        $this->assertDatabaseHas('models', ['id' => $model->id, 'name' => 'After', 'category_id' => $desktop->id]);
    }

    #[Test]
    public function clone_cannot_bypass_the_rule(): void
    {
        $user = $this->superUser();
        $group = $this->group('Hardware');
        $laptop = $this->finalCategory('Laptop', $group);
        $source = AssetModel::factory()->create(['name' => 'Source', 'category_id' => $laptop->id]);

        // The clone form renders and posts to models.store like create.
        $this->actingAs($user)->get(route('models.clone.create', $source))->assertOk()
            ->assertSee(route('models.store'), false);

        $this->actingAs($user)
            ->from(route('models.clone.create', $source))
            ->post(route('models.store'), [
                'name' => 'Cloned into group',
                'category_id' => $group->id,
                'clone_image_from_id' => $source->id,
            ])
            ->assertRedirect(route('models.clone.create', $source))
            ->assertSessionHasErrors(['category_id' => $this->navigationMessage()]);
        $this->assertFalse(AssetModel::where('name', 'Cloned into group')->exists());

        // The legacy clone.store route has no controller method, so it can
        // never write a model either.
        $this->actingAs($user)
            ->post(route('models.clone.store', $source), ['name' => 'Legacy clone', 'category_id' => $group->id]);
        $this->assertFalse(AssetModel::where('name', 'Legacy clone')->exists());

        $this->actingAs($user)
            ->post(route('models.store'), ['name' => 'Cloned properly', 'category_id' => $laptop->id])
            ->assertSessionHasNoErrors();
        $this->assertTrue(AssetModel::where('name', 'Cloned properly')->exists());
    }

    // ---------------------------------------------------------------
    // Restore
    // ---------------------------------------------------------------

    private function restoreThroughWeb(AssetModel $model)
    {
        return $this->actingAs($this->superUser())
            ->from(route('models.index'))
            ->post(route('models.restore.store', $model->id));
    }

    #[Test]
    public function restore_fails_when_the_category_is_now_a_navigation_group(): void
    {
        $category = $this->finalCategory('Was final');
        $model = AssetModel::factory()->create(['category_id' => $category->id]);
        $model->delete();
        // Converting directly in the database: the hierarchy action itself
        // refuses to convert a category that soft-deleted models reference.
        Category::whereKey($category->id)->update(['is_assignable' => false]);

        $this->restoreThroughWeb($model)
            ->assertRedirect(route('models.index'))
            ->assertSessionHas('error', trans('general.could_not_restore', ['item_type' => trans('general.asset_model'), 'error' => $this->navigationMessage()]));

        $this->assertSoftDeleted($model);
    }

    #[Test]
    public function restore_fails_when_the_category_is_deleted_missing_or_not_an_asset_category(): void
    {
        $deletedCategory = $this->finalCategory('Deleted');
        $missingCategory = $this->finalCategory('Missing');
        $changedCategory = $this->finalCategory('Changed type');

        $models = [];
        foreach (['deleted' => $deletedCategory, 'missing' => $missingCategory, 'not_asset' => $changedCategory] as $problem => $category) {
            $models[$problem] = AssetModel::factory()->create(['category_id' => $category->id]);
            $models[$problem]->delete();
        }
        $deletedCategory->delete();
        $missingCategory->forceDelete();
        Category::whereKey($changedCategory->id)->update(['category_type' => 'accessory']);

        foreach ($models as $problem => $model) {
            $this->restoreThroughWeb($model)
                ->assertSessionHas('error', trans('general.could_not_restore', ['item_type' => trans('general.asset_model'), 'error' => trans('admin/models/message.category_rule.'.$problem)]));
            $this->assertSoftDeleted($model);
        }
    }

    #[Test]
    public function restore_succeeds_when_the_category_is_still_a_live_final_asset_category(): void
    {
        $laptop = $this->finalCategory('Laptop', $this->group('Hardware'));
        $model = AssetModel::factory()->create(['category_id' => $laptop->id]);
        $model->delete();

        $this->restoreThroughWeb($model)->assertSessionHas('success');

        $this->assertNotSoftDeleted($model);
    }

    // ---------------------------------------------------------------
    // Raw bulk-update paths
    // ---------------------------------------------------------------

    /** @return array{0: AssetModel, 1: AssetModel, 2: Category, 3: Manufacturer} */
    private function bulkFixture(): array
    {
        $laptop = $this->finalCategory('Laptop');
        $manufacturer = Manufacturer::factory()->create();
        $a = AssetModel::factory()->create(['category_id' => $laptop->id]);
        $b = AssetModel::factory()->create(['category_id' => $laptop->id]);

        return [$a, $b, $laptop, $manufacturer];
    }

    private function bulkPayload(array $ids, $categoryId, int $manufacturerId): array
    {
        return [
            'ids' => $ids,
            'category_id' => $categoryId,
            'manufacturer_id' => $manufacturerId,
            'fieldset_id' => 'NC',
            'depreciation_id' => 'NC',
        ];
    }

    private function assertUntouched(AssetModel $model): void
    {
        $fresh = $model->fresh();
        $this->assertSame($model->category_id, $fresh->category_id);
        $this->assertSame($model->manufacturer_id, $fresh->manufacturer_id);
    }

    #[Test]
    public function bulk_edit_route_rejects_group_and_non_asset_categories_atomically(): void
    {
        [$a, $b, , $manufacturer] = $this->bulkFixture();
        $group = $this->group('Hardware');
        $accessoryCategory = Category::factory()->forAccessories()->create();

        foreach ([[$group->id, 'navigation'], [$accessoryCategory->id, 'not_asset']] as [$categoryId, $problem]) {
            $message = trans('admin/models/message.category_rule.'.$problem);

            $this->actingAs($this->superUser())
                ->post(route('models.bulkedit.store'), $this->bulkPayload([$a->id, $b->id], $categoryId, $manufacturer->id))
                ->assertRedirect(route('models.index'))
                ->assertSessionHasErrors(['category_id' => $message])
                ->assertSessionHas('error', trans('admin/models/message.bulkedit.invalid_category', ['message' => $message]));

            // Zero models updated: not even the manufacturer changed.
            $this->assertUntouched($a);
            $this->assertUntouched($b);
        }
    }

    #[Test]
    public function bulk_edit_route_still_moves_models_to_a_final_category(): void
    {
        [$a, $b, , $manufacturer] = $this->bulkFixture();
        $desktop = $this->finalCategory('Desktop', $this->group('Hardware'));

        $this->actingAs($this->superUser())
            ->post(route('models.bulkedit.store'), $this->bulkPayload([$a->id, $b->id], $desktop->id, $manufacturer->id))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        foreach ([$a, $b] as $model) {
            $this->assertSame($desktop->id, $model->fresh()->category_id);
            $this->assertSame($manufacturer->id, $model->fresh()->manufacturer_id);
        }
    }

    #[Test]
    public function the_legacy_controller_bulk_save_rejects_group_and_non_asset_categories_atomically(): void
    {
        // AssetModelsController::postBulkEditSave has no route today, but it
        // performs a raw update, so it is protected and tested directly.
        [$a, $b, , $manufacturer] = $this->bulkFixture();
        $group = $this->group('Hardware');
        $accessoryCategory = Category::factory()->forAccessories()->create();
        $desktop = $this->finalCategory('Desktop');
        $this->actingAs($this->superUser());

        foreach ([$group->id, $accessoryCategory->id] as $categoryId) {
            $response = app(AssetModelsController::class)->postBulkEditSave(
                Request::create('/models/bulksave', 'POST', $this->bulkPayload([$a->id, $b->id], $categoryId, $manufacturer->id))
            );

            $this->assertSame(route('models.index'), $response->getTargetUrl());
            $this->assertTrue($response->getSession()->get('errors')->has('category_id'));
            $this->assertUntouched($a);
            $this->assertUntouched($b);
        }

        app(AssetModelsController::class)->postBulkEditSave(
            Request::create('/models/bulksave', 'POST', $this->bulkPayload([$a->id, $b->id], $desktop->id, $manufacturer->id))
        );
        $this->assertSame($desktop->id, $a->fresh()->category_id);
        $this->assertSame($desktop->id, $b->fresh()->category_id);
    }

    #[Test]
    public function bulk_edit_without_a_category_change_is_unchanged(): void
    {
        [$a, $b, $laptop, $manufacturer] = $this->bulkFixture();

        $this->actingAs($this->superUser())
            ->post(route('models.bulkedit.store'), $this->bulkPayload([$a->id, $b->id], 'NC', $manufacturer->id))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame($laptop->id, $a->fresh()->category_id);
        $this->assertSame($manufacturer->id, $a->fresh()->manufacturer_id);
    }
}
