<?php

namespace Tests\Feature\Assets\CategoryPermissions;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetCategoryPermission;
use App\Models\Category;
use App\Models\Import;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Importing\AssetsImportFileBuilder;
use Tests\TestCase;

/**
 * ERS Phase 5B2 regression and security guarantees around the new write
 * enforcement: what it must NOT change (View filtering, checkout, check-in,
 * audit, CSV import), and how it behaves (checks before writes, no partial
 * writes, no per-asset permission queries, no automatic or direct grants,
 * no hard-coded categories).
 */
class AssetCategoryWriteRegressionTest extends TestCase
{
    use BuildsWriteEnforcementFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAssets();
    }

    #[Test]
    public function phase_5b1_view_filtering_is_intact(): void
    {
        // Create/Edit/Delete grants never imply View.
        $user = $this->writer([['leaf1' => ['create', 'update', 'delete']], ['leaf2' => ['view']]]);

        $ids = collect($this->actingAsForApi($user)->getJson(route('api.assets.index'))->json('rows'))->pluck('id')->all();

        $this->assertSame([$this->asset['leaf2']->id], $ids);
        $this->assertNotSame('success', $this->actingAsForApi($user)->patchJson(route('api.assets.update', $this->asset['leaf1']->id), ['name' => 'x'])->json('status'));
    }

    #[Test]
    public function new_final_categories_get_no_automatic_grants(): void
    {
        $before = AssetCategoryPermission::query()->count();
        $user = $this->writer([['leaf1' => ['view', 'create', 'update', 'delete']]]);
        $grantRows = AssetCategoryPermission::query()->count();

        $fresh = $this->finalCategory($this->randomName('Brand new'), $this->cat['groupA']);
        $asset = $this->assetIn($fresh);
        $this->flushPermissions();

        $this->assertSame($grantRows, AssetCategoryPermission::query()->count());
        $this->assertGreaterThan($before, $grantRows);
        $this->assertNotSame('success', $this->actingAsForApi($user)->postJson(route('api.assets.store'), [
            'asset_tag' => $this->randomName('NEW'), 'model_id' => $asset->model_id, 'status_id' => $this->readyStatus()->id,
        ])->json('status'));
        $this->assertNotSame('success', $this->actingAsForApi($user)->getJson(route('api.assets.show', $asset->id))->json('status'));
    }

    #[Test]
    public function grants_are_group_only_with_no_direct_user_grants(): void
    {
        $this->assertTrue(Schema::hasColumn('asset_category_permissions', 'group_id'));
        $this->assertFalse(Schema::hasColumn('asset_category_permissions', 'user_id'));

        // Global "assets.*" permissions on the user alone never grant a category.
        $user = User::factory()->create(['permissions' => json_encode(self::ALL_ASSET_PERMISSIONS)]);
        $this->assertSame(0, $this->actingAsForApi($user)->getJson(route('api.assets.index'))->json('total'));
    }

    #[Test]
    public function checkout_checkin_and_audit_are_not_category_gated(): void
    {
        // Category View only: no Create, Edit or Delete grant anywhere.
        $user = $this->writer([['leaf1' => ['view']]], [
            'assets.view' => '1', 'assets.checkout' => '1', 'assets.checkin' => '1', 'assets.audit' => '1',
        ]);
        $asset = $this->asset['leaf1'];
        $assignee = User::factory()->create();

        $this->actingAsForApi($user)->postJson(route('api.asset.checkout', $asset->id), [
            'checkout_to_type' => 'user', 'assigned_user' => $assignee->id,
        ])->assertOk()->assertStatusMessageIs('success');
        $this->assertSame($assignee->id, (int) $this->rawAsset($asset)->assigned_to);

        $this->actingAsForApi($user)->postJson(route('api.asset.checkin', $asset->id))->assertOk()->assertStatusMessageIs('success');
        $this->assertNull($this->rawAsset($asset)->assigned_to);

        $this->actingAsForApi($user)->postJson(route('api.asset.audit', $asset->id))->assertOk()->assertStatusMessageIs('success');
    }

    #[Test]
    public function out_of_scope_asset_abilities_are_exactly_as_before_phase_5b2(): void
    {
        // A user who may VIEW the category but has no category Create, Edit
        // or Delete, with the global permissions those features use.
        $user = $this->writer([['leaf1' => ['view']]], [
            'assets.view' => '1', 'assets.edit' => '1', 'assets.delete' => '1', 'assets.files' => '1',
            'assets.checkout' => '1', 'assets.checkin' => '1', 'assets.audit' => '1',
        ]);
        $asset = $this->asset['leaf1'];
        $maintenance = \App\Models\Maintenance::factory()->create(['asset_id' => $asset->id]);

        $this->actingAs($user, 'web');
        $gate = \Illuminate\Support\Facades\Gate::forUser($user);

        // The plain upstream abilities used by notes, maintenances, files,
        // checkout, check-in and audit are unchanged...
        foreach (['update', 'delete', 'files', 'checkout', 'checkin', 'audit', 'view'] as $ability) {
            $this->assertTrue($gate->allows($ability, $asset), $ability);
        }
        $this->assertTrue(\Illuminate\Support\Facades\Gate::allows('update', $maintenance->fresh()));
        $this->assertTrue(\Illuminate\Support\Facades\Gate::allows('view', $maintenance->fresh()));
        $this->assertTrue(\Illuminate\Support\Facades\Gate::allows('create', [\App\Models\Maintenance::class, $asset]));

        // ...while the asset-record abilities need the category grant.
        foreach (['editRecord', 'deleteRecord', 'restoreRecord'] as $ability) {
            $this->assertFalse($gate->allows($ability, $asset), $ability);
        }
        $this->assertFalse(app(\App\Services\AssetCategoryWriteAuthorizer::class)->canClone($asset, $user));

        // Notes still work with the global permission alone.
        $this->actingAsForApi($user)->postJson(route('api.notes.store', $asset), ['note' => 'still allowed'])->assertOk()->assertStatusMessageIs('success');
    }

    #[Test]
    public function the_asset_page_only_offers_record_actions_the_user_may_perform(): void
    {
        $viewOnly = $this->writer([['leaf1' => ['view']]], self::ALL_ASSET_PERMISSIONS);
        $editor = $this->writer([['leaf1' => ['view', 'create', 'update', 'delete']]], self::ALL_ASSET_PERMISSIONS);
        $asset = $this->asset['leaf1'];

        $page = $this->actingAs($viewOnly, 'web')->get(route('hardware.show', $asset))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('hardware.edit', $asset->id), $page);
        $this->assertStringNotContainsString(route('clone/hardware', $asset->id).'"', $page);
        $this->assertStringContainsString('data-target="#createNoteModal"', $page, 'The note action is unchanged.');

        $page = $this->actingAs($editor, 'web')->get(route('hardware.show', $asset))->assertOk()->getContent();
        $this->assertStringContainsString(route('hardware.edit', $asset->id), $page);

        // Table row actions follow the same rule.
        $row = fn (User $user) => collect($this->actingAsForApi($user)->getJson(route('api.assets.index'))->json('rows'))->firstWhere('id', $asset->id)['available_actions'];
        $this->assertFalse($row($viewOnly)['update']);
        $this->assertFalse($row($viewOnly)['delete']);
        $this->assertFalse($row($viewOnly)['clone']);
        // Non-record actions do not depend on the category grant.
        foreach (['checkout', 'checkin', 'audit'] as $action) {
            $this->assertSame($row($editor)[$action], $row($viewOnly)[$action], $action);
        }
        $this->assertSame($row($editor)['bulk_selectable']['maintenance'], $row($viewOnly)['bulk_selectable']['maintenance']);
        $this->assertTrue($row($editor)['update']);
        $this->assertTrue($row($editor)['delete']);
        $this->assertTrue($row($editor)['clone']);
    }

    #[Test]
    public function the_native_csv_import_is_super_admin_only(): void
    {
        // ERS Phase 6A: the native importer bypasses asset-category
        // permissions, so it is restricted to Super Admin. Ordinary users
        // import assets through the secure /hardware/import workflow.
        $importer = $this->writer([['leaf1' => ['view', 'create']]], ['assets.view' => '1', 'assets.create' => '1', 'assets.edit' => '1', 'import' => '1']);
        $file = AssetsImportFileBuilder::new();
        $row = $file->firstRow();
        $import = Import::factory()->asset()->create(['file_path' => $file->saveToImportsDirectory(), 'created_by' => $importer->id]);
        $this->beforeApplicationDestroyed(fn () => Storage::delete('private_uploads/imports/'.$import->file_path));
        // The import request raises PHP's memory_limit for the whole process
        // (ItemImportRequest); put it back so later tests are unaffected.
        $memoryLimit = ini_get('memory_limit');
        $this->beforeApplicationDestroyed(fn () => ini_set('memory_limit', $memoryLimit));

        $this->actingAsForApi($importer)
            ->postJson(route('api.imports.importFile', ['import' => $import->id]), ['import' => $import->id, 'import-type' => 'asset'])
            ->assertForbidden();
        $this->assertSame(0, Asset::withoutGlobalScopes()->where('serial', $row['serialNumber'])->count());

        $this->actingAsForApi($this->superUser())
            ->postJson(route('api.imports.importFile', ['import' => $import->id]), ['import' => $import->id, 'import-type' => 'asset'])
            ->assertOk()
            ->assertJsonPath('payload.tally.created', 1);
        $this->assertSame(1, Asset::withoutGlobalScopes()->where('serial', $row['serialNumber'])->count());
    }

    #[Test]
    public function category_checks_run_before_any_write_and_failures_leave_the_database_unchanged(): void
    {
        $assignee = User::factory()->create();
        $asset = $this->asset['leaf1'];
        $asset->checkOut($assignee, $this->superUser());
        $user = $this->writer([['leaf1' => ['view', 'update'], 'leaf2' => ['view']]]);
        $archived = \App\Models\Statuslabel::factory()->archived()->create();
        $snapshot = (array) $this->rawAsset($asset);
        $logs = Actionlog::withoutGlobalScopes()->count();
        $assets = $this->assetCount();

        // An archived status would normally check the asset in (writing a
        // log) before saving; the forbidden destination must stop it first.
        $this->actingAs($user, 'web')->put(route('hardware.update', $asset->id), [
            'model_id' => $this->asset['leaf2']->model_id,
            'status_id' => $archived->id,
            'asset_tags' => [1 => $asset->asset_tag],
            'name' => 'Should not stick',
        ])->assertSessionHasErrors('model_id');

        $this->assertSame($snapshot, (array) $this->rawAsset($asset));
        $this->assertSame($logs, Actionlog::withoutGlobalScopes()->count());
        $this->assertSame($assets, $this->assetCount());
    }

    #[Test]
    public function bulk_authorisation_adds_no_per_asset_permission_queries(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'update', 'delete']]]);
        $measure = function (int $count, string $action) use ($user): int {
            $ids = collect(range(1, $count))->map(fn () => $this->assetIn($this->cat['leaf1'])->id)->all();
            $this->flushPermissions();
            DB::flushQueryLog();
            DB::enableQueryLog();
            match ($action) {
                'bulk-update' => $this->actingAsForApi($user)->patchJson(route('api.assets.bulk-update'), ['ids' => $ids, 'notes' => 'q']),
                'bulk-edit-form' => $this->actingAs($user, 'web')->post(route('hardware.bulkedit.show'), ['ids' => $ids, 'bulk_actions' => 'delete']),
            };
            // Permission resolution (grant lookups) and the model/category
            // lookups used to authorise the assets.
            $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'asset_category_permissions')
                || preg_match('/^select \* from "models" where "models"\."id" (=|in)/', $q['query']))->count();
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($measure(2, 'bulk-edit-form'), $measure(8, 'bulk-edit-form'));
        $this->assertSame($measure(2, 'bulk-update'), $measure(8, 'bulk-update'));
    }

    #[Test]
    public function no_category_ids_or_names_are_hard_coded_in_the_write_enforcement(): void
    {
        foreach ([
            'app/Services/AssetCategoryWriteAuthorizer.php',
            'app/Rules/AuthorisedAssetModel.php',
            'app/Rules/AssetModelCategoryChange.php',
            'app/Policies/AssetPolicy.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));
            $this->assertDoesNotMatchRegularExpression('/category_id[\'"]?\s*(=>|=|,)\s*\d+/', $source, $path);
            $this->assertDoesNotMatchRegularExpression('/allows\([^)]*,\s*\d+\s*\)/', $source, $path);
            foreach (Category::query()->pluck('name') as $name) {
                $this->assertStringNotContainsString((string) $name, $source, $path);
            }
        }
    }
}
