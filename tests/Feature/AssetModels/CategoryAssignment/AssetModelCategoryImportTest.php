<?php

namespace Tests\Feature\AssetModels\CategoryAssignment;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Import;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Categories\Hierarchy\CreatesCategoryHierarchy;
use Tests\Support\Importing\AssetModelsImportFileBuilder;
use Tests\Support\Importing\AssetsImportFileBuilder;
use Tests\Support\Importing\CategoriesImportFileBuilder;
use Tests\Support\Importing\CleansUpImportFiles;
use Tests\TestCase;

/**
 * CSV importer category matching (ERS Phase 3). The existing importer is
 * kept; only its category assignment is constrained.
 */
class AssetModelCategoryImportTest extends TestCase
{
    use CleansUpImportFiles;
    use CreatesCategoryHierarchy;

    private function runImport(Import $import, string $type, bool $update = false): TestResponse
    {
        $parameters = ['import' => $import->id, 'import-type' => $type, 'send-welcome' => 0];
        if ($update) {
            $parameters['import-update'] = true;
        }

        return $this->actingAsForApi($this->superUser())
            ->postJson(route('api.imports.importFile', $parameters), $parameters);
    }

    private function navigationError(Category $group): array
    {
        return ['category' => [trans('admin/models/message.import_navigation_category', ['name' => $group->name])]];
    }

    #[Test]
    public function asset_model_import_rejects_a_row_whose_category_is_a_navigation_group(): void
    {
        $group = $this->group('Hardware');
        $builder = AssetModelsImportFileBuilder::new(['category' => 'Hardware', 'name' => 'Group Model']);
        $import = Import::factory()->assetmodel()->create(['file_path' => $builder->saveToImportsDirectory()]);

        $this->runImport($import, 'assetModel')
            ->assertInternalServerError()
            ->assertJsonPath('status', 'import-errors')
            ->assertJsonPath('payload.tally', ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errored' => 1])
            ->assertJsonPath('messages.Hardware.category', $this->navigationError($group));

        $this->assertFalse(AssetModel::withTrashed()->where('name', 'Group Model')->exists());
        $group->refresh();
        $this->assertTrue($group->isNavigationOnly(), 'the group must not be converted');
        $this->assertSame(0, AssetModel::withTrashed()->where('category_id', $group->id)->count());
    }

    #[Test]
    public function asset_model_import_update_mode_cannot_move_an_existing_model_into_a_group(): void
    {
        $laptop = $this->finalCategory('Laptop');
        $this->group('Hardware');
        $model = AssetModel::factory()->create(['name' => 'Existing', 'model_number' => 'X1', 'category_id' => $laptop->id]);
        $builder = new AssetModelsImportFileBuilder([['name' => 'Existing', 'model_number' => 'X1', 'category' => 'Hardware']]);
        $import = Import::factory()->assetmodel()->create(['file_path' => $builder->saveToImportsDirectory()]);

        $this->runImport($import, 'assetModel', update: true)
            ->assertInternalServerError()
            ->assertJsonPath('payload.tally.errored', 1)
            ->assertJsonPath('payload.tally.updated', 0);

        $this->assertSame($laptop->id, $model->fresh()->category_id);
    }

    #[Test]
    public function asset_model_import_with_a_final_category_still_succeeds(): void
    {
        $laptop = $this->finalCategory('Laptop', $this->group('Hardware'));
        $builder = AssetModelsImportFileBuilder::new(['category' => 'Laptop', 'name' => 'Final Model']);
        $import = Import::factory()->assetmodel()->create(['file_path' => $builder->saveToImportsDirectory()]);

        $this->runImport($import, 'assetModel')
            ->assertOk()
            ->assertJsonPath('payload.tally', ['created' => 1, 'updated' => 0, 'skipped' => 0, 'errored' => 0]);

        $this->assertSame($laptop->id, AssetModel::where('name', 'Final Model')->sole()->category_id);
    }

    #[Test]
    public function a_file_with_group_and_final_rows_imports_only_the_final_row(): void
    {
        $group = $this->group('Hardware');
        $laptop = $this->finalCategory('Laptop', $group);
        $builder = new AssetModelsImportFileBuilder([
            ['name' => 'Rejected', 'model_number' => 'R1', 'category' => 'Hardware'],
            ['name' => 'Accepted', 'model_number' => 'A1', 'category' => 'Laptop'],
        ]);
        $import = Import::factory()->assetmodel()->create(['file_path' => $builder->saveToImportsDirectory()]);

        $this->runImport($import, 'assetModel')
            ->assertInternalServerError()
            ->assertJsonPath('payload.tally', ['created' => 1, 'updated' => 0, 'skipped' => 0, 'errored' => 1]);

        $this->assertFalse(AssetModel::where('name', 'Rejected')->exists());
        $this->assertSame($laptop->id, AssetModel::where('name', 'Accepted')->sole()->category_id);
    }

    #[Test]
    public function auto_created_asset_categories_are_root_level_final_categories(): void
    {
        $builder = AssetModelsImportFileBuilder::new(['category' => 'Brand New Category', 'name' => 'New Model']);
        $import = Import::factory()->assetmodel()->create(['file_path' => $builder->saveToImportsDirectory()]);

        $this->runImport($import, 'assetModel')->assertOk();

        $category = Category::where('name', 'Brand New Category')->sole();
        $this->assertSame('asset', $category->category_type);
        $this->assertNull($category->parent_id);
        $this->assertTrue($category->fresh()->is_assignable);
        $this->assertSame($category->id, AssetModel::where('name', 'New Model')->sole()->category_id);
    }

    #[Test]
    public function asset_import_rejects_a_row_whose_category_is_a_navigation_group(): void
    {
        $group = $this->group('Hardware');
        $builder = AssetsImportFileBuilder::new(['category' => 'Hardware', 'model' => 'Group Asset Model']);
        $row = $builder->firstRow();
        $import = Import::factory()->asset()->create(['file_path' => $builder->saveToImportsDirectory()]);

        $this->runImport($import, 'asset')
            ->assertInternalServerError()
            ->assertJsonPath('payload.tally.created', 0)
            ->assertJsonPath('payload.tally.errored', 1)
            ->assertJsonPath('messages.Hardware.category', $this->navigationError($group));

        $this->assertFalse(Asset::where('asset_tag', $row['tag'])->exists());
        $this->assertFalse(AssetModel::where('name', 'Group Asset Model')->exists());
        $this->assertSame(0, AssetModel::withTrashed()->where('category_id', $group->id)->count());
    }

    #[Test]
    public function asset_import_with_a_final_category_still_succeeds(): void
    {
        $laptop = $this->finalCategory('Laptop', $this->group('Hardware'));
        $builder = AssetsImportFileBuilder::new(['category' => 'Laptop', 'model' => 'Laptop Asset Model']);
        $row = $builder->firstRow();
        $import = Import::factory()->asset()->create(['file_path' => $builder->saveToImportsDirectory()]);

        $this->runImport($import, 'asset')
            ->assertOk()
            ->assertJsonPath('payload.tally.created', 1);

        $asset = Asset::where('asset_tag', $row['tag'])->sole();
        $this->assertSame($laptop->id, $asset->model->category_id);
    }

    #[Test]
    public function category_import_cannot_change_the_type_of_a_category_that_models_use(): void
    {
        $laptop = $this->finalCategory('Laptop');
        AssetModel::factory()->create(['category_id' => $laptop->id]);
        $builder = new CategoriesImportFileBuilder([['name' => 'Laptop', 'category_type' => 'accessory']]);
        $import = Import::factory()->categories()->create(['file_path' => $builder->saveToImportsDirectory()]);

        $this->runImport($import, 'category', update: true)
            ->assertInternalServerError()
            ->assertJsonPath('payload.tally.errored', 1)
            ->assertJsonPath('messages.Laptop.category_type.category_type.0', trans('admin/categories/message.update.cannot_change_category_type_in_use'));

        $this->assertSame('asset', $laptop->fresh()->category_type);
    }

    #[Test]
    public function category_import_cannot_change_the_type_of_a_navigation_group(): void
    {
        $group = $this->group('Hardware');
        $builder = new CategoriesImportFileBuilder([['name' => 'Hardware', 'category_type' => 'license']]);
        $import = Import::factory()->categories()->create(['file_path' => $builder->saveToImportsDirectory()]);

        $this->runImport($import, 'category', update: true)->assertInternalServerError();

        $group->refresh();
        $this->assertSame('asset', $group->category_type);
        $this->assertTrue($group->isNavigationOnly());
    }

    #[Test]
    public function category_import_updates_are_otherwise_unchanged(): void
    {
        $laptop = $this->finalCategory('Laptop', $this->group('Hardware'));
        $builder = new CategoriesImportFileBuilder([['name' => 'Laptop', 'category_type' => 'asset', 'notes' => 'Imported note']]);
        $import = Import::factory()->categories()->create(['file_path' => $builder->saveToImportsDirectory()]);

        $this->runImport($import, 'category', update: true)
            ->assertOk()
            ->assertJsonPath('payload.tally.updated', 1);

        $laptop->refresh();
        $this->assertSame('Imported note', $laptop->notes);
        $this->assertNotNull($laptop->parent_id, 'hierarchy placement is preserved');
    }
}
