<?php

namespace Tests\Feature\Assets\Import;

use App\Models\AssetImportSession;
use App\Models\AssetModel;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 6A: no step of the secure import writes anything except the
 * private upload and the asset_import_sessions row. Every protected table
 * is compared in full before and after each successful step.
 */
class AssetImportNoWritesTest extends TestCase
{
    use BuildsAssetImportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportFixture();
    }

    #[Test]
    public function every_successful_step_leaves_assets_and_master_data_untouched(): void
    {
        $user = $this->importer();
        $this->customFieldOn($this->modelIn('leaf1'));
        $before = $this->protectedSnapshot();

        $steps = [
            'index' => fn () => $this->actingAs($user, 'web')->get(route('hardware.import.index'))->assertOk(),
            'upload' => fn () => $this->upload($user, $this->csvFile("Asset Tag,Model,Status,Company,Location,Supplier,Serial Number\nNEW-1,Brand New Model,Brand New Status,Brand New Company,Brand New Location,Brand New Supplier,SER-NEW\n"))->assertRedirect(),
        ];
        foreach ($steps as $name => $step) {
            $step();
            $this->assertSame($before, $this->protectedSnapshot(), $name);
        }

        $session = AssetImportSession::query()->sole();
        $steps = [
            'target page' => fn () => $this->actingAs($user, 'web')->get(route('hardware.import.target', [$session->public_id, 'category' => $this->cat['leaf1']->id]))->assertOk(),
            'target save' => fn () => $this->postTarget($user, $session, [
                'model_source' => 'column', 'status_source' => 'column', 'company_source' => 'column', 'location_source' => 'column',
            ])->assertSessionHasNoErrors(),
            'mapping page' => fn () => $this->actingAs($user, 'web')->get(route('hardware.import.mapping', $session->public_id))->assertOk(),
            'mapping save' => fn () => $this->postMapping($user, $session, [
                0 => 'standard:asset_tag', 1 => 'standard:model', 2 => 'standard:status', 3 => 'standard:company',
                4 => 'standard:location', 5 => 'standard:supplier', 6 => 'standard:serial',
            ])->assertSessionHasNoErrors(),
            'review page' => fn () => $this->actingAs($user, 'web')->get(route('hardware.import.review', $session->public_id))->assertOk(),
        ];
        foreach ($steps as $name => $step) {
            $step();
            $this->assertSame($before, $this->protectedSnapshot(), $name);
        }

        $this->assertSame(AssetImportSession::STATE_MAPPED, $session->fresh()->state);
        $this->assertSame(1, AssetImportSession::query()->count());
        $this->assertSame([$session->storage_path], $this->storedFiles());
        foreach (['assets', 'models', 'status_labels', 'companies', 'locations', 'suppliers'] as $table) {
            $this->assertSame(0, DB::table($table)->where(fn ($q) => $q->where('name', 'like', 'Brand New%')->orWhere('name', 'like', 'NEW-%'))->count(), $table);
        }
    }

    #[Test]
    public function refused_steps_write_nothing_either(): void
    {
        $user = $this->importer();
        $session = $this->newSession($user);
        $before = $this->protectedSnapshot();
        $sessionBefore = $session->fresh()->getAttributes();

        $this->postTarget($user, $session, ['category_id' => $this->cat['other']->id])->assertSessionHasErrors();
        $this->postMapping($user, $session, [0 => 'standard:id'])->assertSessionHasErrors();
        $this->upload($user, $this->csvFile("bad\n", 'bad.txt'))->assertSessionHasErrors();

        $this->assertSame($before, $this->protectedSnapshot());
        $this->assertSame($sessionBefore, $session->fresh()->getAttributes());
        $this->assertSame([$session->storage_path], $this->storedFiles());
    }

    #[Test]
    public function the_pages_do_not_query_once_per_model_or_custom_field(): void
    {
        $user = $this->importer();
        $session = $this->newSession($user);
        $this->postTarget($user, $session, ['model_source' => 'column'])->assertSessionHasNoErrors();
        $this->postMapping($user, $session, [0 => 'standard:asset_tag', 2 => 'standard:model'])->assertSessionHasNoErrors();

        $count = function () use ($user, $session) {
            $queries = [];
            foreach (['hardware.import.target', 'hardware.import.mapping', 'hardware.import.review'] as $route) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->actingAs($user, 'web')->get(route($route, $session->public_id))->assertOk();
                $queries[$route] = count(DB::getQueryLog());
                DB::disableQueryLog();
            }

            return $queries;
        };

        $this->customFieldOn($this->modelIn('leaf1'));
        $count(); // warm per-request caches (settings, translations)
        $baseline = $count();

        for ($i = 0; $i < 5; $i++) {
            $model = AssetModel::factory()->create(['category_id' => $this->cat['leaf1']->id]);
            $this->customFieldOn($model);
            $this->customFieldOn($model);
        }

        $this->assertSame($baseline, $count());
    }
}
