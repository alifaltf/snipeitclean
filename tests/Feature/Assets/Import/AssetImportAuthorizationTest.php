<?php

namespace Tests\Feature\Assets\Import;

use App\Livewire\Importer;
use App\Models\Asset;
use App\Models\AssetImportSession;
use App\Models\Import;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Importing\AssetsImportFileBuilder;
use Tests\TestCase;

/**
 * ERS Phase 6A: who may use the secure asset import, and the restriction
 * of the native importer (page, Livewire actions, download and every
 * api/v1/imports endpoint) to Super Admin.
 */
class AssetImportAuthorizationTest extends TestCase
{
    use BuildsAssetImportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportFixture();
    }

    /** @return array<string, array{0: string, 1: string}> every Phase 6A route [method, url] */
    private function routesFor(AssetImportSession $session): array
    {
        return [
            'index' => ['get', route('hardware.import.index')],
            'store' => ['post', route('hardware.import.store')],
            'target' => ['get', route('hardware.import.target', $session->public_id)],
            'target.update' => ['post', route('hardware.import.target.update', $session->public_id)],
            'mapping' => ['get', route('hardware.import.mapping', $session->public_id)],
            'mapping.update' => ['post', route('hardware.import.mapping.update', $session->public_id)],
            'review' => ['get', route('hardware.import.review', $session->public_id)],
        ];
    }

    private function assertDeniedEverywhere(User $user, AssetImportSession $session): void
    {
        foreach ($this->routesFor($session) as $name => [$method, $url]) {
            $this->actingAs($user, 'web')->{$method}($url, $method === 'post' ? ['csv_file' => $this->csvFile()] : [])
                ->assertForbidden();
        }
        $this->assertSame(1, AssetImportSession::query()->count(), 'No session may be created.');
    }

    #[Test]
    public function without_the_global_import_permission_every_route_is_denied(): void
    {
        $session = $this->mappedSession($this->importer());
        $user = $this->writer([['leaf1' => ['view', 'create']]], ['assets.view' => '1', 'assets.create' => '1']);

        $this->assertDeniedEverywhere($user, $session);
    }

    #[Test]
    public function import_without_global_assets_create_is_denied(): void
    {
        $session = $this->mappedSession($this->importer());
        $user = $this->writer([['leaf1' => ['view', 'create']]], ['import' => '1', 'assets.view' => '1']);

        $this->assertDeniedEverywhere($user, $session);
    }

    #[Test]
    public function import_and_create_without_any_category_create_grant_is_denied(): void
    {
        $session = $this->mappedSession($this->importer());
        $user = $this->importer([['leaf1' => ['view', 'update', 'delete']]]);

        $this->assertDeniedEverywhere($user, $session);
    }

    #[Test]
    public function an_admin_without_category_create_is_denied(): void
    {
        $session = $this->mappedSession($this->importer());
        $admin = $this->writer([['leaf1' => ['view']]], self::IMPORTER_PERMISSIONS + ['admin' => '1']);

        $this->assertDeniedEverywhere($admin, $session);
    }

    #[Test]
    public function category_create_without_category_view_is_enough(): void
    {
        $user = $this->importer([['leaf1' => ['create']]]);

        $session = $this->mappedSession($user);

        $this->actingAs($user, 'web')->get(route('hardware.import.review', $session->public_id))->assertOk();
        $this->assertSame(AssetImportSession::STATE_MAPPED, $session->state);
    }

    #[Test]
    public function super_admin_may_import_into_every_live_final_category_and_nothing_else(): void
    {
        $admin = $this->superUser();
        $session = $this->newSession($admin);

        $page = $this->actingAs($admin, 'web')->get(route('hardware.import.target', $session->public_id))->assertOk();
        foreach ($this->finalIds() as $id) {
            $page->assertSee('value="'.$id.'"', false);
        }
        foreach (['groupA', 'branch', 'groupB', 'emptyGroup', 'deleted', 'accessory'] as $key) {
            $page->assertDontSee('<option value="'.$this->cat[$key]->id.'"', false);
        }

        $this->postTarget($admin, $session, ['category_id' => $this->cat['other']->id, 'model_id' => $this->asset['other']->model_id])
            ->assertRedirect(route('hardware.import.mapping', $session->public_id));
        $this->postTarget($admin, $session, ['category_id' => $this->cat['groupA']->id])->assertSessionHasErrors('category_id');
    }

    #[Test]
    public function revoking_a_permission_mid_flow_closes_every_step(): void
    {
        $user = $this->importer();
        $session = $this->mappedSession($user);

        $user->permissions = json_encode(['assets.view' => '1', 'assets.create' => '1']);
        $user->save();
        $this->flushPermissions();

        $this->assertDeniedEverywhere($user->fresh(), $session);
    }

    #[Test]
    public function the_menu_offers_the_secure_import_to_importers_and_the_native_importer_only_to_super_admin(): void
    {
        $page = $this->actingAs($this->importer(), 'web')->get(route('hardware.import.index'))->assertOk();
        $page->assertSee(route('hardware.import.index'), false)->assertDontSee('id="import-sidenav-option"', false);

        $this->actingAs($this->superUser(), 'web')->get(route('hardware.import.index'))->assertOk()
            ->assertSee('id="import-sidenav-option"', false)
            ->assertSee('id="asset-import-sidenav-option"', false);
    }

    // ---------------------------------------------------------------
    // Native importer restriction
    // ---------------------------------------------------------------

    #[Test]
    public function the_native_import_page_and_livewire_component_are_super_admin_only(): void
    {
        $importer = $this->importer([['leaf1' => ['view', 'create', 'update', 'delete']]], ['admin' => '1']);

        $this->actingAs($importer, 'web')->get(route('imports.index'))->assertForbidden();
        Livewire::actingAs($importer)->test(Importer::class)->assertForbidden();

        $this->actingAs($this->superUser(), 'web')->get(route('imports.index'))->assertOk();
        Livewire::actingAs($this->superUser())->test(Importer::class)->assertOk();
    }

    #[Test]
    public function direct_native_import_api_calls_are_refused_for_ordinary_users(): void
    {
        $importer = $this->importer([['leaf1' => ['view', 'create', 'update', 'delete']]], ['admin' => '1']);
        $file = AssetsImportFileBuilder::new();
        $import = Import::factory()->asset()->create(['file_path' => $file->saveToImportsDirectory(), 'created_by' => $importer->id]);
        $this->beforeApplicationDestroyed(fn () => Storage::delete('private_uploads/imports/'.$import->file_path));
        $assets = Asset::withoutGlobalScopes()->count();
        $imports = Import::query()->count();

        $this->actingAsForApi($importer)->getJson(route('api.imports.index'))->assertForbidden();
        $this->actingAsForApi($importer)->post(route('api.imports.store'), ['files' => [$this->csvFile()]])->assertForbidden();
        $this->actingAsForApi($importer)
            ->postJson(route('api.imports.importFile', ['import' => $import->id]), ['import-type' => 'asset'])
            ->assertForbidden();
        $this->actingAsForApi($importer)->postJson(route('api.imports.importFile', ['import' => $import->id]), [])->assertForbidden();
        $this->actingAsForApi($importer)->deleteJson(route('api.imports.destroy', ['import' => $import->id]))->assertForbidden();
        $this->actingAs($importer, 'web')->get(route('imports.download', $import))->assertForbidden();

        $this->assertSame($assets, Asset::withoutGlobalScopes()->count(), 'No asset may be created.');
        $this->assertSame($imports, Import::query()->count(), 'No import may be added or removed.');
        $this->assertNull(Import::query()->find($import->id)->processing_by);
    }

    #[Test]
    public function super_admin_keeps_the_native_importer(): void
    {
        $admin = $this->superUser();
        $file = AssetsImportFileBuilder::new();
        $row = $file->firstRow();
        $import = Import::factory()->asset()->create(['file_path' => $file->saveToImportsDirectory(), 'created_by' => $admin->id]);
        $this->beforeApplicationDestroyed(fn () => Storage::delete('private_uploads/imports/'.$import->file_path));
        $memoryLimit = ini_get('memory_limit');
        $this->beforeApplicationDestroyed(fn () => ini_set('memory_limit', $memoryLimit));

        $this->actingAsForApi($admin)->getJson(route('api.imports.index'))->assertOk();
        $this->actingAsForApi($admin)
            ->postJson(route('api.imports.importFile', ['import' => $import->id]), ['import-type' => 'asset'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame(1, Asset::withoutGlobalScopes()->where('serial', $row['serialNumber'])->count());
    }
}
