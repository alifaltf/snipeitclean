<?php

namespace Tests\Feature\Assets\Import;

use App\Models\AssetImportSession;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 6A: where the secure import can be started from. The sidebar
 * link and each final category's "Import CSV" button follow exactly the
 * rules the import controller enforces; a category carried in from a
 * button is only ever a pre-selection, re-validated at every step.
 */
class AssetImportEntryPointsTest extends TestCase
{
    use BuildsAssetImportFixture;

    private const SECURE_LINK = 'id="asset-import-sidenav-option"';

    private const LEGACY_LINK = 'id="import-sidenav-option"';

    private const CATEGORY_BUTTON = 'id="asset-category-import"';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportFixture();
    }

    private function sidebar(User $user): string
    {
        return (string) $this->actingAs($user, 'web')->get(route('profile'))->assertOk()->getContent();
    }

    private function categoryPage(User $user, string $key): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'web')->get(route('hardware.index', ['asset_category' => $this->cat[$key]->id]))->assertOk();
    }

    // ---------------------------------------------------------------
    // Sidebar
    // ---------------------------------------------------------------

    #[Test]
    public function import_and_create_without_any_category_create_shows_no_link_and_every_route_stays_forbidden(): void
    {
        $user = $this->importer([['leaf1' => ['view', 'update']]]);

        $page = $this->sidebar($user);
        $this->assertStringNotContainsString(self::SECURE_LINK, $page);
        $this->assertStringNotContainsString(self::LEGACY_LINK, $page);

        $this->actingAs($user, 'web')->get(route('hardware.import.index'))->assertForbidden();
        $this->upload($user)->assertForbidden();
        $this->assertSame(0, AssetImportSession::query()->count());
    }

    #[Test]
    public function category_create_without_view_shows_the_link_and_the_workflow_works(): void
    {
        $user = $this->importer([['leaf1' => ['create']]]);

        $this->assertStringContainsString(self::SECURE_LINK, $this->sidebar($user));
        $session = $this->mappedSession($user);
        $this->actingAs($user, 'web')->get(route('hardware.import.review', $session->public_id))->assertOk();
    }

    #[Test]
    public function an_admin_without_category_create_sees_no_secure_link(): void
    {
        $admin = $this->writer([['leaf1' => ['view']]], self::IMPORTER_PERMISSIONS + ['admin' => '1']);

        $page = $this->sidebar($admin);
        $this->assertStringNotContainsString(self::SECURE_LINK, $page);
        $this->assertStringNotContainsString(self::LEGACY_LINK, $page);
    }

    #[Test]
    public function super_admin_sees_both_links_and_an_ordinary_importer_only_the_secure_one(): void
    {
        $page = $this->sidebar($this->superUser());
        $this->assertStringContainsString(self::SECURE_LINK, $page);
        $this->assertStringContainsString(self::LEGACY_LINK, $page);

        $page = $this->sidebar($this->importer());
        $this->assertStringContainsString(self::SECURE_LINK, $page);
        $this->assertStringNotContainsString(self::LEGACY_LINK, $page);
    }

    // ---------------------------------------------------------------
    // Per-category "Import CSV" button
    // ---------------------------------------------------------------

    #[Test]
    public function a_final_category_with_view_and_create_offers_import_and_preselects_it(): void
    {
        $user = $this->importer([['leaf1' => ['view', 'create'], 'leaf2' => ['view', 'create']]]);

        $page = $this->categoryPage($user, 'leaf2');
        $page->assertSee(self::CATEGORY_BUTTON, false)
            ->assertSee(route('hardware.import.index', ['category' => $this->cat['leaf2']->id]), false);

        $this->actingAs($user, 'web')->get(route('hardware.import.index', ['category' => $this->cat['leaf2']->id]))->assertOk()
            ->assertSee('name="category" value="'.$this->cat['leaf2']->id.'"', false)
            ->assertSee($this->cat['leaf2']->name);

        $response = $this->actingAs($user, 'web')->post(route('hardware.import.store'), ['csv_file' => $this->csvFile(), 'category' => $this->cat['leaf2']->id]);
        $session = AssetImportSession::query()->sole();
        $response->assertRedirect(route('hardware.import.target', [$session->public_id, 'category' => $this->cat['leaf2']->id]));
        $this->assertNull($session->category_id, 'Uploading only carries the choice; it is saved on the target step.');

        $this->actingAs($user, 'web')->get(route('hardware.import.target', [$session->public_id, 'category' => $this->cat['leaf2']->id]))->assertOk()
            ->assertSee('<option value="'.$this->cat['leaf2']->id.'" selected', false)
            ->assertSee($this->modelIn('leaf2')->name);

        $this->postTarget($user, $session, ['category_id' => $this->cat['leaf2']->id, 'model_id' => $this->asset['leaf2']->model_id])->assertSessionHasNoErrors();
        $this->assertSame($this->cat['leaf2']->id, $session->fresh()->category_id);
    }

    #[Test]
    public function view_only_navigation_groups_and_users_without_import_get_no_button(): void
    {
        $viewOnly = $this->importer([['leaf1' => ['view'], 'leaf2' => ['view', 'create']]]);
        $this->categoryPage($viewOnly, 'leaf1')->assertDontSee(self::CATEGORY_BUTTON, false);

        // A navigation group never offers it, even to Super Admin.
        $this->categoryPage($viewOnly, 'branch')->assertDontSee(self::CATEGORY_BUTTON, false);
        $this->categoryPage($this->superUser(), 'groupA')->assertDontSee(self::CATEGORY_BUTTON, false);

        // Category Create without global import is not enough.
        $noImport = $this->writer([['leaf1' => ['view', 'create']]], ['assets.view' => '1', 'assets.create' => '1']);
        $this->categoryPage($noImport, 'leaf1')->assertDontSee(self::CATEGORY_BUTTON, false);

        // The unfiltered list has no category button either.
        $this->actingAs($this->superUser(), 'web')->get(route('hardware.index'))->assertOk()->assertDontSee(self::CATEGORY_BUTTON, false);
    }

    #[Test]
    public function super_admin_gets_the_button_on_every_live_final_category(): void
    {
        $admin = $this->superUser();

        foreach (['leaf1', 'leaf2', 'direct', 'other', 'loose'] as $key) {
            $this->categoryPage($admin, $key)->assertSee(self::CATEGORY_BUTTON, false);
        }
    }

    #[Test]
    public function forged_or_out_of_scope_categories_are_never_preselected_or_saved(): void
    {
        $user = $this->importer([['leaf1' => ['view', 'create'], 'leaf2' => ['view']]]);

        foreach ([
            'view only' => $this->cat['leaf2']->id,
            'other scope' => $this->cat['other']->id,
            'navigation' => $this->cat['groupA']->id,
            'deleted' => $this->cat['deleted']->id,
            'non-asset' => $this->cat['accessory']->id,
            'nonexistent' => 999999,
            'malformed' => '1;DROP',
        ] as $case => $categoryId) {
            $this->actingAs($user, 'web')->get(route('hardware.import.index', ['category' => $categoryId]))->assertOk()
                ->assertDontSee('name="category"', false)
                ->assertDontSee('id="asset-import-preselected-category"', false);

            $response = $this->actingAs($user, 'web')->post(route('hardware.import.store'), ['csv_file' => $this->csvFile(), 'category' => $categoryId]);
            $session = AssetImportSession::query()->latest('id')->first();
            $response->assertRedirect(route('hardware.import.target', $session->public_id));

            $this->actingAs($user, 'web')->get(route('hardware.import.target', [$session->public_id, 'category' => $categoryId]))->assertOk()
                ->assertDontSee('selected>', false);
            $this->postTarget($user, $session, ['category_id' => $categoryId])->assertSessionHasErrors('category_id');
            $this->assertNull($session->fresh()->category_id, $case);
        }
    }

    #[Test]
    public function a_category_whose_grant_is_revoked_after_upload_is_no_longer_accepted(): void
    {
        $user = $this->importer([['leaf1' => ['view', 'create'], 'leaf2' => ['view', 'create']]]);
        $this->actingAs($user, 'web')->post(route('hardware.import.store'), ['csv_file' => $this->csvFile(), 'category' => $this->cat['leaf2']->id]);
        $session = AssetImportSession::query()->sole();

        \App\Models\AssetCategoryPermission::query()->where('category_id', $this->cat['leaf2']->id)->update(['can_create' => false]);
        $this->flushPermissions();

        $this->actingAs($user, 'web')->get(route('hardware.import.target', [$session->public_id, 'category' => $this->cat['leaf2']->id]))->assertOk()
            ->assertDontSee('<option value="'.$this->cat['leaf2']->id.'"', false)
            ->assertDontSee($this->modelIn('leaf2')->name);
        $this->postTarget($user, $session, ['category_id' => $this->cat['leaf2']->id, 'model_id' => $this->asset['leaf2']->model_id])
            ->assertSessionHasErrors('category_id');
        $this->assertNull($session->fresh()->category_id);
        $this->categoryPage($user, 'leaf2')->assertDontSee(self::CATEGORY_BUTTON, false);
    }

    #[Test]
    public function the_entry_points_contain_no_hard_coded_category_ids_or_names(): void
    {
        foreach ([
            'app/Http/Controllers/Assets/AssetImportController.php',
            'app/Http/Controllers/Assets/AssetsController.php',
            'app/View/Composers/SidebarComposer.php',
            'app/Services/AssetImport/AssetImportAuthorizer.php',
            'resources/views/hardware/index.blade.php',
            'resources/views/hardware/import/index.blade.php',
        ] as $file) {
            $source = file_get_contents(base_path($file));
            foreach (['Laptop', 'Desktop', 'Hardware', 'Furniture', 'category_id = ', "'category' => 1", 'asset_category=1'] as $needle) {
                $this->assertStringNotContainsString($needle, $source, "$file contains $needle");
            }
        }
    }
}
