<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Company;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Feature tests for the ERS-specific Assets sidebar (see
 * config/ers_assets.php, App\Models\Category, App\View\Composers\SidebarComposer,
 * App\Http\Controllers\Assets\AssetsController@index,
 * resources/views/layouts/default.blade.php and
 * resources/views/hardware/index.blade.php).
 *
 * "Hardware" and "Software" are VIRTUAL sidebar groups only — neither is
 * a Snipe-IT category and neither has a category_id. Which EXISTING
 * asset categories appear under each is resolved entirely from the
 * database, via each category's own `ers_asset_group` column
 * (category_type = asset AND ers_asset_group = 'hardware'/'software')
 * — never from a config-file name allow-list and never from a
 * hard-coded category ID. This feature introduces NO new controller,
 * table, route, or API endpoint: everything reuses hardware.index /
 * GET /api/v1/hardware / existing Assets permissions / existing asset
 * workflows. The legacy "Hardware Devices" category is deliberately
 * left with ers_asset_group = null, same as every other pre-existing
 * category, so it never appears under either virtual group.
 */
class ErsAssetsSidebarTest extends TestCase
{
    /**
     * Creates a handful of throwaway UNGROUPED categories before the
     * real ones, so the categories created after them are guaranteed to
     * NOT land on suspiciously "round" IDs like 1 or 2. This is what
     * lets the "category IDs are not hard-coded" tests below actually
     * prove something, rather than coincidentally passing because the
     * real categories happened to get low IDs. These filler categories
     * are also, incidentally, proof that plain category_type = asset
     * categories with no ers_asset_group set (the state EVERY
     * pre-existing category is left in by the migration) never show up
     * in either virtual group.
     */
    private function createFillerCategories(int $count = 4): void
    {
        Category::factory()->count($count)->create();
    }

    private function createAssetCategory(string $name, ?string $ersGroup = null): Category
    {
        return Category::factory()->create([
            'name' => $name,
            'category_type' => 'asset',
            'ers_asset_group' => $ersGroup,
        ]);
    }

    /**
     * Creates Hardware-group child categories. Pass a subset of names to
     * simulate some of them not (yet) being assigned to the group.
     *
     * @return array<string, Category> keyed by category name
     */
    private function createHardwareCategories(array $names = ['Laptop', 'Desktop', 'Monitor', 'Phone']): array
    {
        $categories = [];
        foreach ($names as $name) {
            $categories[$name] = $this->createAssetCategory($name, 'hardware');
        }

        return $categories;
    }

    private function createAssetInCategory(Category $category, ?Company $company = null): Asset
    {
        $model = AssetModel::factory()->create(['category_id' => $category->id]);

        return Asset::factory()
            ->when($company, fn ($factory) => $factory->for($company))
            ->create(['model_id' => $model->id]);
    }

    /**
     * Pulls the data-url of the assets datatable (the one built from
     * route('api.assets.index', [...]) in hardware/index.blade.php) out of
     * a rendered hardware.index response, so assertions can target that
     * URL specifically instead of the whole page (which also contains
     * category_id-bearing hrefs in the sidebar links themselves). The
     * value is both HTML- and URL-decoded, so a multi-category
     * "category_id=6,7,8,9" aggregate reads back as plain commas rather
     * than %2C.
     */
    private function extractAssetsApiDataUrl(string $html): string
    {
        $this->assertMatchesRegularExpression('/data-url="([^"]*\/api\/v1\/hardware[^"]*)"/', $html);
        preg_match('/data-url="([^"]*\/api\/v1\/hardware[^"]*)"/', $html, $matches);

        return urldecode(html_entity_decode($matches[1]));
    }

    /**
     * Extracts the (possibly comma-joined) category_id value from a
     * hardware.index response's assets-table data-url, as a sorted list
     * of ints, so aggregate assertions ("All Hardware combines these
     * four IDs") don't depend on resolution order.
     *
     * @return list<int>
     */
    private function extractApiCategoryIds(string $html): array
    {
        $apiUrl = $this->extractAssetsApiDataUrl($html);

        if (! preg_match('/[?&]category_id=([0-9,]+)/', $apiUrl, $matches)) {
            return [];
        }

        $ids = array_map('intval', explode(',', $matches[1]));
        sort($ids);

        return $ids;
    }

    /**
     * Narrows a full page render down to just the expandable "Assets"
     * treeview submenu in the left sidebar (List All / Hardware /
     * Software / status filters), INCLUDING the nested Hardware/Software
     * sub-trees. The page also renders a top icon-bar shortcut with an
     * identical `href="{url}/hardware"` that carries its own unrelated
     * "active" logic, so active-state assertions must be scoped to this
     * block rather than searching the whole document.
     *
     * A plain non-greedy regex can't safely capture this any more now
     * that Hardware/Software nest their own <ul class="treeview-menu">
     * inside it, so this walks the HTML counting <ul>/</ul> to find the
     * true matching close.
     */
    private function extractAssetsTreeviewMenu(string $html): string
    {
        $needle = '<ul class="treeview-menu">';
        $start = strpos($html, $needle);
        $this->assertNotFalse($start, 'Could not find the Assets treeview-menu in the rendered page.');

        $cursor = $start + strlen($needle);
        $depth = 1;

        while ($depth > 0) {
            $nextOpen = strpos($html, '<ul', $cursor);
            $nextClose = strpos($html, '</ul>', $cursor);
            $this->assertNotFalse($nextClose, 'Unbalanced <ul> tags while scanning the Assets treeview-menu.');

            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $depth++;
                $cursor = $nextOpen + 3;
            } else {
                $depth--;
                $cursor = $nextClose + 5;
            }
        }

        return substr($html, $start, $cursor - $start);
    }

    /**
     * Isolates the opening <li ...> tag that wraps a given sidebar link
     * href within the Assets treeview submenu, so active-state assertions
     * can target one specific submenu item. Used for "List All", which
     * (unlike every ERS item) has no stable id to key off of.
     */
    private function assertSidebarLinkActive(string $html, string $href, bool $expectedActive, string $message = ''): void
    {
        $treeview = $this->extractAssetsTreeviewMenu($html);

        $pattern = '/<li([^>]*)>\s*<a href="'.preg_quote($href, '/').'"/';
        $this->assertMatchesRegularExpression($pattern, $treeview, 'Could not locate sidebar <li> for href '.$href.'. '.$message);
        preg_match($pattern, $treeview, $matches);

        $isActive = str_contains($matches[1], 'active');

        $this->assertSame($expectedActive, $isActive, $message ?: 'Expected active='.($expectedActive ? 'true' : 'false')." for sidebar link {$href}");
    }

    /**
     * Isolates a sidebar <li id="..."> (every ERS group/child item has a
     * stable, unique id) and checks whether its class attribute carries
     * "active".
     */
    private function assertSidebarItemActiveById(string $html, string $id, bool $expectedActive, string $message = ''): void
    {
        $pattern = '/<li id="'.preg_quote($id, '/').'"([^>]*)>/';
        $this->assertMatchesRegularExpression($pattern, $html, "Could not find sidebar <li id=\"{$id}\">. ".$message);
        preg_match($pattern, $html, $matches);

        $isActive = str_contains($matches[1], 'active');

        $this->assertSame($expectedActive, $isActive, $message ?: 'Expected active='.($expectedActive ? 'true' : 'false')." for id={$id}");
    }

    /**
     * Builds the id a Hardware/Software child sidebar item gets for a
     * given category — always derived from the category's own (DB-
     * assigned) id, never a name-based slug or anything hard-coded,
     * mirroring exactly how App\View\Composers\SidebarComposer builds it.
     */
    private function childSidenavId(string $groupKey, Category $category): string
    {
        return "ers-{$groupKey}-{$category->id}-sidenav-option";
    }

    // ------------------------------------------------------------------
    // Config: presentation only, no category allow-list, no IDs
    // ------------------------------------------------------------------

    public function test_ers_assets_config_defines_only_group_presentation_never_categories_or_ids(): void
    {
        $config = config('ers_assets');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('hardware', $config);
        $this->assertArrayHasKey('software', $config);

        foreach (['hardware', 'software'] as $groupKey) {
            $this->assertArrayHasKey('label', $config[$groupKey]);
            $this->assertArrayHasKey('all_label', $config[$groupKey]);
            $this->assertArrayHasKey('all_title', $config[$groupKey]);

            // The old category-name allow-list must be gone entirely.
            $this->assertArrayNotHasKey('categories', $config[$groupKey]);
        }

        // No category IDs, and no category names, anywhere in the config tree.
        array_walk_recursive($config, function ($value, $key) {
            $this->assertNotSame('category_id', $key, 'config(\'ers_assets\') must never store a raw category_id.');
            $this->assertNotSame('category_name', $key, 'config(\'ers_assets\') must never store a category name allow-list.');
        });
    }

    // ------------------------------------------------------------------
    // Proof point: List All stays unfiltered, and shows ungrouped assets
    // ------------------------------------------------------------------

    public function test_list_all_renders_without_any_category_filtering(): void
    {
        $this->createFillerCategories();
        $categories = $this->createHardwareCategories();
        $this->createAssetInCategory($categories['Laptop']);

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk();

        $apiUrl = $this->extractAssetsApiDataUrl($response->getContent());

        $this->assertStringNotContainsString('category_id', $apiUrl, 'List All must not pass any category_id to the assets API.');
    }

    /**
     * An asset whose category has never been assigned an ers_asset_group
     * (i.e. every pre-existing category right after the migration) must
     * still show up through List All — it's simply absent from the
     * Hardware/Software virtual groups, not hidden from the app.
     */
    public function test_ungrouped_assets_remain_visible_through_list_all(): void
    {
        $this->createFillerCategories();
        $ungrouped = $this->createAssetCategory('Legacy Category');
        $this->assertNull($ungrouped->ers_asset_group);

        $asset = $this->createAssetInCategory($ungrouped);

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.assets.index'))
            ->assertOk()
            ->assertResponseContainsInRows($asset, 'asset_tag');
    }

    public function test_list_all_sidebar_link_is_active_only_when_no_group_or_category_is_selected(): void
    {
        $this->createFillerCategories();
        $categories = $this->createHardwareCategories();

        $allAssetsHref = url('hardware');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();
        $this->assertSidebarLinkActive($html, $allAssetsHref, true);

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['category_id' => $categories['Laptop']->id]))
            ->assertOk()
            ->getContent();
        $this->assertSidebarLinkActive($html, $allAssetsHref, false, 'List All must not remain active when category_id is present.');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'hardware']))
            ->assertOk()
            ->getContent();
        $this->assertSidebarLinkActive($html, $allAssetsHref, false, 'List All must not remain active when asset_group is present.');
    }

    // ------------------------------------------------------------------
    // Proof point: Hardware and Software are virtual parents, not
    // categories themselves
    // ------------------------------------------------------------------

    public function test_hardware_and_software_are_virtual_groups_not_categories(): void
    {
        $this->createFillerCategories();
        $this->createHardwareCategories();
        $this->createAssetCategory('Digital Systems', 'software');

        $this->assertSame(0, Category::query()->where('name', 'Hardware')->count());
        $this->assertSame(0, Category::query()->where('name', 'Software')->count());

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();

        // The group parent itself is not a filterable link — it only
        // toggles its nested submenu open.
        $this->assertMatchesRegularExpression('/<li id="ers-hardware-sidenav-option"[^>]*>\s*<a href="#">/', $html);
        $this->assertMatchesRegularExpression('/<li id="ers-software-sidenav-option"[^>]*>\s*<a href="#">/', $html);

        // Visiting the page never creates "Hardware"/"Software" categories.
        $this->assertSame(0, Category::query()->where('name', 'Hardware')->count());
        $this->assertSame(0, Category::query()->where('name', 'Software')->count());
    }

    // ------------------------------------------------------------------
    // Proof point: All Hardware aggregates every category whose
    // ers_asset_group = 'hardware'
    // ------------------------------------------------------------------

    public function test_all_hardware_aggregates_every_category_assigned_to_the_hardware_group(): void
    {
        $this->createFillerCategories();
        $categories = $this->createHardwareCategories();
        $digitalSystems = $this->createAssetCategory('Digital Systems', 'software');
        // Legacy category, deliberately left ungrouped — must never be
        // pulled into the aggregate, even though it's a perfectly valid
        // category_type=asset category.
        $legacy = $this->createAssetCategory('Hardware Devices');

        $expectedIds = collect($categories)->pluck('id')->sort()->values()->all();

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'hardware']))
            ->assertOk();

        $html = $response->getContent();
        $this->assertSame($expectedIds, $this->extractApiCategoryIds($html));

        $apiUrl = $this->extractAssetsApiDataUrl($html);
        $this->assertStringNotContainsString((string) $digitalSystems->id, explode('category_id=', $apiUrl)[1] ?? '');
        $this->assertStringNotContainsString((string) $legacy->id, explode('category_id=', $apiUrl)[1] ?? '');

        $this->assertStringContainsString('Hardware Assets', $html);
    }

    public function test_all_hardware_returns_assets_from_every_hardware_category_only(): void
    {
        $this->createFillerCategories();
        $categories = $this->createHardwareCategories();
        $digitalSystems = $this->createAssetCategory('Digital Systems', 'software');
        $legacy = $this->createAssetCategory('Hardware Devices');

        $laptopAsset = $this->createAssetInCategory($categories['Laptop']);
        $desktopAsset = $this->createAssetInCategory($categories['Desktop']);
        $monitorAsset = $this->createAssetInCategory($categories['Monitor']);
        $phoneAsset = $this->createAssetInCategory($categories['Phone']);
        $softwareAsset = $this->createAssetInCategory($digitalSystems);
        $legacyAsset = $this->createAssetInCategory($legacy);

        // Resolve the aggregate exactly the way the page does, then hit
        // the SAME, pre-existing, unmodified GET /api/v1/hardware
        // endpoint directly with it.
        $categoryIds = implode(',', collect($categories)->pluck('id')->all());

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.assets.index', ['category_id' => $categoryIds]))
            ->assertOk()
            ->assertResponseContainsInRows($laptopAsset, 'asset_tag')
            ->assertResponseContainsInRows($desktopAsset, 'asset_tag')
            ->assertResponseContainsInRows($monitorAsset, 'asset_tag')
            ->assertResponseContainsInRows($phoneAsset, 'asset_tag')
            ->assertResponseDoesNotContainInRows($softwareAsset, 'asset_tag')
            ->assertResponseDoesNotContainInRows($legacyAsset, 'asset_tag');
    }

    // ------------------------------------------------------------------
    // Proof point: Laptop returns only Laptop-category assets (and the
    // other Hardware children behave equivalently)
    // ------------------------------------------------------------------

    public function test_laptop_returns_only_laptop_category_assets(): void
    {
        $this->createFillerCategories();
        $categories = $this->createHardwareCategories();

        $laptopAsset = $this->createAssetInCategory($categories['Laptop']);
        $desktopAsset = $this->createAssetInCategory($categories['Desktop']);

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['category_id' => $categories['Laptop']->id]))
            ->assertOk();

        $this->assertSame([$categories['Laptop']->id], $this->extractApiCategoryIds($response->getContent()));
        $this->assertStringContainsString('Laptop Assets', $response->getContent());

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.assets.index', ['category_id' => $categories['Laptop']->id]))
            ->assertOk()
            ->assertResponseContainsInRows($laptopAsset, 'asset_tag')
            ->assertResponseDoesNotContainInRows($desktopAsset, 'asset_tag');
    }

    #[DataProvider('hardwareChildCategoryProvider')]
    public function test_each_hardware_child_category_filters_and_titles_correctly(string $categoryName, string $expectedTitle): void
    {
        $this->createFillerCategories();
        $categories = $this->createHardwareCategories();
        $matching = $this->createAssetInCategory($categories[$categoryName]);
        $other = $this->createAssetInCategory($categories[$categoryName === 'Laptop' ? 'Desktop' : 'Laptop']);

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['category_id' => $categories[$categoryName]->id]))
            ->assertOk();

        $this->assertSame([$categories[$categoryName]->id], $this->extractApiCategoryIds($response->getContent()));
        $this->assertStringContainsString($expectedTitle, $response->getContent());

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.assets.index', ['category_id' => $categories[$categoryName]->id]))
            ->assertOk()
            ->assertResponseContainsInRows($matching, 'asset_tag')
            ->assertResponseDoesNotContainInRows($other, 'asset_tag');
    }

    public static function hardwareChildCategoryProvider(): array
    {
        return [
            'Laptop' => ['Laptop', 'Laptop Assets'],
            'Desktop' => ['Desktop', 'Desktop Assets'],
            'Monitor' => ['Monitor', 'Monitor Assets'],
            'Phone' => ['Phone', 'Phone Assets'],
        ];
    }

    // ------------------------------------------------------------------
    // Proof point: Digital Systems / All Software still work
    // ------------------------------------------------------------------

    public function test_digital_systems_still_works(): void
    {
        $this->createFillerCategories();
        $this->createHardwareCategories();
        $digitalSystems = $this->createAssetCategory('Digital Systems', 'software');

        $matching = $this->createAssetInCategory($digitalSystems);
        $other = $this->createAssetInCategory($this->createAssetCategory('Some Other Category'));

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['category_id' => $digitalSystems->id]))
            ->assertOk();

        $this->assertSame([$digitalSystems->id], $this->extractApiCategoryIds($response->getContent()));
        $this->assertStringContainsString('Digital Systems Assets', $response->getContent());

        $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.assets.index', ['category_id' => $digitalSystems->id]))
            ->assertOk()
            ->assertResponseContainsInRows($matching, 'asset_tag')
            ->assertResponseDoesNotContainInRows($other, 'asset_tag');
    }

    public function test_all_software_aggregates_and_titles_correctly(): void
    {
        $this->createFillerCategories();
        $digitalSystems = $this->createAssetCategory('Digital Systems', 'software');

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'software']))
            ->assertOk();

        $this->assertSame([$digitalSystems->id], $this->extractApiCategoryIds($response->getContent()));
        $this->assertStringContainsString('Software Assets', $response->getContent());
    }

    // ------------------------------------------------------------------
    // Proof point: a category newly assigned to a group automatically
    // shows up under it, and moving it moves it — no config change
    // ------------------------------------------------------------------

    public function test_newly_created_hardware_category_automatically_appears_under_hardware(): void
    {
        $this->createFillerCategories();

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();
        // No categories assigned yet: Hardware group is hidden.
        $this->assertStringNotContainsString('id="ers-hardware-sidenav-option"', $html);

        // Create a brand-new category and assign it to Hardware — no
        // config file touched, no code change, just a normal DB write
        // (exactly what saving the category edit form does).
        $newCategory = $this->createAssetCategory('Brand New Hardware Category', 'hardware');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="ers-hardware-sidenav-option"', $html);
        $this->assertStringContainsString('id="'.$this->childSidenavId('hardware', $newCategory).'"', $html);
    }

    public function test_newly_created_software_category_automatically_appears_under_software(): void
    {
        $this->createFillerCategories();

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('id="ers-software-sidenav-option"', $html);

        $newCategory = $this->createAssetCategory('Brand New Software Category', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="ers-software-sidenav-option"', $html);
        $this->assertStringContainsString('id="'.$this->childSidenavId('software', $newCategory).'"', $html);
    }

    public function test_updating_a_categorys_group_moves_it_between_virtual_sidebar_groups(): void
    {
        $this->createFillerCategories();
        $category = $this->createAssetCategory('Movable Category', 'hardware');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('id="'.$this->childSidenavId('hardware', $category).'"', $html);
        $this->assertStringNotContainsString('id="ers-software-sidenav-option"', $html);

        // Simulate exactly what CategoriesController@update does when an
        // administrator edits the category and changes its Asset Group.
        $category->ers_asset_group = 'software';
        $this->assertTrue($category->save());

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('id="ers-hardware-sidenav-option"', $html);
        $this->assertStringContainsString('id="'.$this->childSidenavId('software', $category).'"', $html);
    }

    // ------------------------------------------------------------------
    // Proof point: the asset table retains the Model column
    // ------------------------------------------------------------------

    #[DataProvider('modelColumnScenarioProvider')]
    public function test_asset_table_retains_the_model_column(array $query): void
    {
        $this->createFillerCategories();
        $this->createHardwareCategories();
        $this->createAssetCategory('Digital Systems', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', $query))
            ->assertOk()
            ->getContent();

        // data-columns is rendered via {{ }} (HTML-entity-escaped), so
        // "field":"model" reads back as &quot;field&quot;:&quot;model&quot;.
        $this->assertMatchesRegularExpression('/"field"\s*:\s*"model"/', html_entity_decode($html), 'The Model column must remain in the assets table presenter config.');
    }

    public static function modelColumnScenarioProvider(): array
    {
        return [
            'list all' => [[]],
            'asset_group=hardware' => [['asset_group' => 'hardware']],
        ];
    }

    // ------------------------------------------------------------------
    // Proof point: ungrouped categories are hidden individually; empty
    // parent groups are hidden entirely
    // ------------------------------------------------------------------

    public function test_ungrouped_categories_are_hidden_individually(): void
    {
        $this->createFillerCategories();
        // Laptop and Desktop are assigned to Hardware; Monitor and Phone
        // exist as asset categories but are left ungrouped.
        $categories = $this->createHardwareCategories(['Laptop', 'Desktop']);
        $monitor = $this->createAssetCategory('Monitor');
        $phone = $this->createAssetCategory('Phone');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="'.$this->childSidenavId('hardware', $categories['Laptop']).'"', $html);
        $this->assertStringContainsString('id="'.$this->childSidenavId('hardware', $categories['Desktop']).'"', $html);
        $this->assertStringNotContainsString('id="'.$this->childSidenavId('hardware', $monitor).'"', $html);
        $this->assertStringNotContainsString('id="'.$this->childSidenavId('hardware', $phone).'"', $html);

        // The Hardware group itself is still shown, since Laptop/Desktop
        // did resolve.
        $this->assertStringContainsString('id="ers-hardware-sidenav-option"', $html);
        $this->assertStringContainsString('id="ers-hardware-all-sidenav-option"', $html);

        // Aggregating "All Hardware" only combines what's actually assigned.
        $expectedIds = collect($categories)->pluck('id')->sort()->values()->all();
        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'hardware']))
            ->assertOk();
        $this->assertSame($expectedIds, $this->extractApiCategoryIds($response->getContent()));
    }

    public function test_empty_hardware_group_is_hidden_entirely(): void
    {
        $this->createFillerCategories();
        // No categories assigned to Hardware at all.
        $this->createAssetCategory('Digital Systems', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('id="ers-hardware-sidenav-option"', $html);
        $this->assertStringContainsString('id="ers-software-sidenav-option"', $html);

        // List All must still render normally.
        $this->assertStringContainsString(url('hardware'), $html);
    }

    public function test_empty_software_group_is_hidden_entirely(): void
    {
        $this->createFillerCategories();
        $this->createHardwareCategories();
        // No category assigned to Software.

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="ers-hardware-sidenav-option"', $html);
        $this->assertStringNotContainsString('id="ers-software-sidenav-option"', $html);
    }

    /**
     * Every pre-existing category is left with ers_asset_group = null by
     * the migration. This proves those "legacy" categories — even ones
     * that happen to share a name with a Hardware/Software child, like
     * "Laptop" — never appear under either virtual group until an
     * administrator explicitly assigns one.
     */
    public function test_ungrouped_legacy_categories_do_not_appear_under_either_virtual_group(): void
    {
        $this->createFillerCategories();
        $laptop = $this->createAssetCategory('Laptop'); // no group assigned
        $digitalSystems = $this->createAssetCategory('Digital Systems'); // no group assigned

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('id="ers-hardware-sidenav-option"', $html);
        $this->assertStringNotContainsString('id="ers-software-sidenav-option"', $html);
        $this->assertStringNotContainsString('id="'.$this->childSidenavId('hardware', $laptop).'"', $html);
        $this->assertStringNotContainsString('id="'.$this->childSidenavId('software', $digitalSystems).'"', $html);

        // "All Hardware" / "All Software" must not accidentally pick them
        // up either.
        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'hardware']))
            ->assertOk();
        $apiUrl = $this->extractAssetsApiDataUrl($response->getContent());
        $this->assertStringNotContainsString('category_id', $apiUrl);
    }

    public function test_no_category_is_ever_auto_created(): void
    {
        foreach (['Laptop', 'Desktop', 'Monitor', 'Phone', 'Digital Systems', 'Hardware', 'Software'] as $name) {
            $this->assertSame(0, Category::query()->where('name', $name)->count());
        }

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['category_id' => 999999]))
            ->assertOk();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'hardware']))
            ->assertOk();

        foreach (['Laptop', 'Desktop', 'Monitor', 'Phone', 'Digital Systems', 'Hardware', 'Software'] as $name) {
            $this->assertSame(0, Category::query()->where('name', $name)->count());
        }
    }

    // ------------------------------------------------------------------
    // Proof point: invalid / nonnumeric / non-asset / array-shaped IDs,
    // and arbitrary comma-separated IDs, all fail safely
    // ------------------------------------------------------------------

    public function test_nonexistent_category_id_fails_safely_and_falls_back_to_list_all(): void
    {
        $this->createFillerCategories();

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['category_id' => 999999]))
            ->assertOk();

        $apiUrl = $this->extractAssetsApiDataUrl($response->getContent());
        $this->assertStringNotContainsString('category_id', $apiUrl);
    }

    public function test_non_asset_category_id_is_rejected_and_does_not_leak_unrelated_records(): void
    {
        $this->createFillerCategories();

        $accessoryCategory = Category::factory()->create([
            'name' => 'Some Accessory Category',
            'category_type' => 'accessory',
        ]);

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['category_id' => $accessoryCategory->id]))
            ->assertOk();

        $html = $response->getContent();
        $apiUrl = $this->extractAssetsApiDataUrl($html);

        $this->assertStringNotContainsString('category_id='.$accessoryCategory->id, $apiUrl);
        $this->assertStringNotContainsString('Some Accessory Category', $html);
    }

    public function test_non_numeric_category_id_fails_safely(): void
    {
        $this->createFillerCategories();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['category_id' => 'not-a-number']))
            ->assertOk();
    }

    public function test_array_shaped_category_id_fails_safely(): void
    {
        $this->createFillerCategories();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['category_id' => [1, 2, 3]]))
            ->assertOk();
    }

    public function test_array_shaped_asset_group_fails_safely(): void
    {
        $this->createFillerCategories();
        $this->createHardwareCategories();

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => ['hardware']]))
            ->assertOk();

        $apiUrl = $this->extractAssetsApiDataUrl($response->getContent());
        $this->assertStringNotContainsString('category_id', $apiUrl);
    }

    public function test_unknown_asset_group_fails_safely(): void
    {
        $this->createFillerCategories();
        $this->createHardwareCategories();

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'not-a-real-group']))
            ->assertOk();

        $apiUrl = $this->extractAssetsApiDataUrl($response->getContent());
        $this->assertStringNotContainsString('category_id', $apiUrl);
    }

    /**
     * The client must never be able to hand in its own comma-separated
     * category_id list and have it accepted as-is — only a single,
     * all-digit scalar is accepted for category_id. Multiple IDs are
     * only ever produced by the server resolving a known asset_group.
     */
    public function test_arbitrary_comma_separated_category_id_cannot_bypass_validation(): void
    {
        $this->createFillerCategories();
        $categories = $this->createHardwareCategories();
        $digitalSystems = $this->createAssetCategory('Digital Systems', 'software');

        $forgedIds = $categories['Laptop']->id.','.$categories['Desktop']->id.','.$digitalSystems->id;

        $response = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['category_id' => $forgedIds]))
            ->assertOk();

        $apiUrl = $this->extractAssetsApiDataUrl($response->getContent());
        $this->assertStringNotContainsString('category_id', $apiUrl, 'A hand-crafted comma-separated category_id must be ignored, not passed through.');
    }

    // ------------------------------------------------------------------
    // Proof point: company scoping remains enforced, including with
    // asset_group filters
    // ------------------------------------------------------------------

    public function test_company_scoping_is_preserved_with_a_single_category_filter(): void
    {
        $this->createFillerCategories();
        $categories = $this->createHardwareCategories();

        [$companyA, $companyB] = Company::factory()->count(2)->create();

        $assetA = $this->createAssetInCategory($categories['Laptop'], $companyA);
        $assetB = $this->createAssetInCategory($categories['Laptop'], $companyB);

        $userInCompanyA = $companyA->users()->save(User::factory()->viewAssets()->make());

        $this->settings->enableMultipleFullCompanySupport();
        $this->actingAsForApi($userInCompanyA)
            ->getJson(route('api.assets.index', ['category_id' => $categories['Laptop']->id]))
            ->assertOk()
            ->assertResponseContainsInRows($assetA, 'asset_tag')
            ->assertResponseDoesNotContainInRows($assetB, 'asset_tag');

        $this->settings->disableMultipleFullCompanySupport();
        $this->actingAsForApi($userInCompanyA)
            ->getJson(route('api.assets.index', ['category_id' => $categories['Laptop']->id]))
            ->assertOk()
            ->assertResponseContainsInRows($assetA, 'asset_tag')
            ->assertResponseContainsInRows($assetB, 'asset_tag');
    }

    public function test_company_scoping_is_preserved_with_an_asset_group_filter(): void
    {
        $this->createFillerCategories();
        $categories = $this->createHardwareCategories();

        [$companyA, $companyB] = Company::factory()->count(2)->create();

        $assetA = $this->createAssetInCategory($categories['Laptop'], $companyA);
        $assetB = $this->createAssetInCategory($categories['Desktop'], $companyB);

        $userInCompanyA = $companyA->users()->save(User::factory()->viewAssets()->make());

        // Resolve the aggregate exactly the way the controller would.
        $categoryIds = implode(',', collect($categories)->pluck('id')->all());

        $this->settings->enableMultipleFullCompanySupport();
        $this->actingAsForApi($userInCompanyA)
            ->getJson(route('api.assets.index', ['category_id' => $categoryIds]))
            ->assertOk()
            ->assertResponseContainsInRows($assetA, 'asset_tag')
            ->assertResponseDoesNotContainInRows($assetB, 'asset_tag');

        $this->settings->disableMultipleFullCompanySupport();
        $this->actingAsForApi($userInCompanyA)
            ->getJson(route('api.assets.index', ['category_id' => $categoryIds]))
            ->assertOk()
            ->assertResponseContainsInRows($assetA, 'asset_tag')
            ->assertResponseContainsInRows($assetB, 'asset_tag');
    }

    // ------------------------------------------------------------------
    // Proof point: correct parent + child active states are rendered
    // ------------------------------------------------------------------

    public function test_correct_parent_and_child_active_states_for_a_hardware_child(): void
    {
        $this->createFillerCategories();
        $categories = $this->createHardwareCategories();
        $this->createAssetCategory('Digital Systems', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['category_id' => $categories['Laptop']->id]))
            ->assertOk()
            ->getContent();

        $this->assertSidebarItemActiveById($html, 'ers-hardware-sidenav-option', true, 'Hardware parent should be active when a Hardware child is selected.');
        $this->assertSidebarItemActiveById($html, $this->childSidenavId('hardware', $categories['Laptop']), true);
        $this->assertSidebarItemActiveById($html, $this->childSidenavId('hardware', $categories['Desktop']), false);
        $this->assertSidebarItemActiveById($html, $this->childSidenavId('hardware', $categories['Monitor']), false);
        $this->assertSidebarItemActiveById($html, $this->childSidenavId('hardware', $categories['Phone']), false);
        $this->assertSidebarItemActiveById($html, 'ers-hardware-all-sidenav-option', false, '"All Hardware" must not be active when a single child is selected.');
        $this->assertSidebarItemActiveById($html, 'ers-software-sidenav-option', false);
        $this->assertSidebarLinkActive($html, url('hardware'), false);
    }

    public function test_correct_parent_and_child_active_states_for_all_hardware(): void
    {
        $this->createFillerCategories();
        $this->createHardwareCategories();
        $this->createAssetCategory('Digital Systems', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'hardware']))
            ->assertOk()
            ->getContent();

        $this->assertSidebarItemActiveById($html, 'ers-hardware-sidenav-option', true);
        $this->assertSidebarItemActiveById($html, 'ers-hardware-all-sidenav-option', true);
        $this->assertSidebarItemActiveById($html, 'ers-software-sidenav-option', false);
        $this->assertSidebarLinkActive($html, url('hardware'), false);
    }

    public function test_correct_parent_and_child_active_states_for_digital_systems(): void
    {
        $this->createFillerCategories();
        $this->createHardwareCategories();
        $digitalSystems = $this->createAssetCategory('Digital Systems', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['category_id' => $digitalSystems->id]))
            ->assertOk()
            ->getContent();

        $this->assertSidebarItemActiveById($html, 'ers-software-sidenav-option', true);
        $this->assertSidebarItemActiveById($html, $this->childSidenavId('software', $digitalSystems), true);
        $this->assertSidebarItemActiveById($html, 'ers-software-all-sidenav-option', false);
        $this->assertSidebarItemActiveById($html, 'ers-hardware-sidenav-option', false);
        $this->assertSidebarLinkActive($html, url('hardware'), false);
    }

    public function test_correct_active_states_for_list_all(): void
    {
        $this->createFillerCategories();
        $this->createHardwareCategories();
        $this->createAssetCategory('Digital Systems', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();

        $this->assertSidebarLinkActive($html, url('hardware'), true);
        $this->assertSidebarItemActiveById($html, 'ers-hardware-sidenav-option', false);
        $this->assertSidebarItemActiveById($html, 'ers-software-sidenav-option', false);
    }

    // ------------------------------------------------------------------
    // Proof point: category IDs are resolved dynamically at request
    // time, never hard-coded
    // ------------------------------------------------------------------

    public function test_category_ids_are_resolved_dynamically_not_hard_coded(): void
    {
        // A larger, deliberately-ordered set of filler categories, so the
        // real categories' IDs are neither low nor sequential in the
        // order they're created below.
        $this->createFillerCategories(8);
        $phone = $this->createAssetCategory('Phone', 'hardware');
        $laptop = $this->createAssetCategory('Laptop', 'hardware');
        $digitalSystems = $this->createAssetCategory('Digital Systems', 'software');
        $monitor = $this->createAssetCategory('Monitor', 'hardware');
        $desktop = $this->createAssetCategory('Desktop', 'hardware');

        foreach ([$phone, $laptop, $digitalSystems, $monitor, $desktop] as $category) {
            $this->assertGreaterThan(8, $category->id);
        }

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();

        foreach ([$laptop, $desktop, $monitor, $phone] as $category) {
            $this->assertStringContainsString(route('hardware.index', ['category_id' => $category->id]), $html);
        }
        $this->assertStringContainsString(route('hardware.index', ['category_id' => $digitalSystems->id]), $html);
    }

    // ------------------------------------------------------------------
    // Proof point: existing Assets functionality does not regress
    // ------------------------------------------------------------------

    public function test_status_filters_and_order_number_still_work_alongside_ers_sidebar(): void
    {
        $this->createFillerCategories();
        $this->createHardwareCategories();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', [
                'status_type' => 'Deployed',
                'order_number' => '12345',
            ]))
            ->assertOk()
            ->assertSee(trans('general.deployed'), false)
            ->assertSee('Order #12345', false);
    }
}
