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
 * Feature tests for the ERS Assets index navigation and page context:
 * breadcrumb trail, <title>, and the [All Assets] [Hardware] [Software]
 * button group (see App\Providers\BreadcrumbsServiceProvider's
 * 'hardware.index' registration, App\Http\Controllers\Assets\AssetsController@index
 * /ersNavGroups(), and resources/views/hardware/index.blade.php).
 *
 * Everything here is resolved entirely server-side from the database
 * (category_type = asset AND ers_asset_group = 'hardware'/'software')
 * plus config('ers_assets')'s presentation labels — never a hard-coded
 * category name or ID. See tests/Feature/Assets/ErsAssetsSidebarTest.php
 * for the underlying filtering/aggregation coverage this file assumes as
 * already correct; this file focuses on the breadcrumb/title/button
 * layer built on top of it.
 */
class ErsAssetsNavigationTest extends TestCase
{
    private function createFillerCategories(int $count = 3): void
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

    private function createAssetInCategory(Category $category, ?Company $company = null): Asset
    {
        $model = AssetModel::factory()->create(['category_id' => $category->id]);

        return Asset::factory()
            ->when($company, fn ($factory) => $factory->for($company))
            ->create(['model_id' => $model->id]);
    }

    /**
     * Extracts the ordered list of visible breadcrumb labels from the
     * content-header breadcrumb <ul> (see layouts/default.blade.php).
     * The first item is icon-only (Home), normalized to the literal
     * string "Home" here so assertions can compare a plain string array.
     *
     * @return list<string>
     */
    private function extractBreadcrumbLabels(string $html): array
    {
        $found = preg_match('/<ul style="padding-left: 0;">(.*?)<\/ul>/s', $html, $ulMatch);
        $this->assertSame(1, $found, 'Could not find the breadcrumb <ul> in the rendered page.');

        preg_match_all('/<li class="breadcrumb-item[^"]*">(.*?)<\/li>/s', $ulMatch[1], $liMatches);
        $this->assertNotEmpty($liMatches[1], 'Breadcrumb <ul> contained no <li> items.');

        return array_map(function (string $liInner) {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($liInner)));

            return $text === '' ? 'Home' : $text;
        }, $liMatches[1]);
    }

    private function extractTitle(string $html): string
    {
        $found = preg_match('/<title>(.*?)<\/title>/s', $html, $match);
        $this->assertSame(1, $found, 'Could not find <title> in the rendered page.');

        // The <title> tag also carries " :: {site name}" appended by
        // layouts/default.blade.php — only compare the page-specific
        // portion before that separator.
        $normalized = trim(preg_replace('/\s+/', ' ', strip_tags($match[1])));

        return trim(explode('::', $normalized)[0]);
    }

    private function extractNavButtonsHtml(string $html): string
    {
        $found = preg_match('/<div class="btn-group ers-assets-nav".*?<\/div>/s', $html, $match);
        $this->assertSame(1, $found, 'Could not find the ERS assets nav button group in the rendered page.');

        return $match[0];
    }

    /**
     * @return array{href: string, active: bool}|null null if no button
     *                                                with this exact visible label exists.
     */
    private function extractNavButton(string $navHtml, string $label): ?array
    {
        $found = preg_match(
            '/<a\s+href="([^"]*)"\s+class="([^"]*)">\s*'.preg_quote($label, '/').'\s*<\/a>/s',
            $navHtml,
            $match
        );

        if ($found !== 1) {
            return null;
        }

        return [
            'href' => html_entity_decode($match[1]),
            'active' => str_contains($match[2], 'active'),
        ];
    }

    private function assertNavButtonActive(string $html, string $label, bool $expectedActive, string $message = ''): void
    {
        $button = $this->extractNavButton($this->extractNavButtonsHtml($html), $label);
        $this->assertNotNull($button, "Nav button \"{$label}\" was not found. ".$message);
        $this->assertSame($expectedActive, $button['active'], $message ?: "Expected active={$expectedActive} for nav button \"{$label}\"");
    }

    private function assertNavButtonAbsent(string $html, string $label, string $message = ''): void
    {
        $button = $this->extractNavButton($this->extractNavButtonsHtml($html), $label);
        $this->assertNull($button, "Nav button \"{$label}\" should not be present. ".$message);
    }

    /**
     * Extracts the second-row "[All Hardware] [Desktop] [Laptop] ..."
     * category nav block (see resources/views/hardware/index.blade.php),
     * or null when it isn't rendered at all (bare All Assets page, or an
     * active group with no assigned categories).
     */
    private function extractCategoryNavHtml(string $html): ?string
    {
        $found = preg_match('/<div class="ers-assets-category-nav".*?<\/div>\s*<\/div>/s', $html, $match);

        return $found === 1 ? $match[0] : null;
    }

    private function assertCategoryRowAbsent(string $html, string $message = ''): void
    {
        $this->assertNull($this->extractCategoryNavHtml($html), 'The category nav row should not be present. '.$message);
    }

    private function assertCategoryButtonActive(string $html, string $label, bool $expectedActive, string $message = ''): void
    {
        $navHtml = $this->extractCategoryNavHtml($html);
        $this->assertNotNull($navHtml, 'The category nav row was not found. '.$message);

        $button = $this->extractNavButton($navHtml, $label);
        $this->assertNotNull($button, "Category button \"{$label}\" was not found. ".$message);
        $this->assertSame($expectedActive, $button['active'], $message ?: "Expected active={$expectedActive} for category button \"{$label}\"");
    }

    private function assertCategoryButtonAbsent(string $html, string $label, string $message = ''): void
    {
        $navHtml = $this->extractCategoryNavHtml($html);

        if ($navHtml === null) {
            // No row at all is the strongest form of "this button is
            // absent" and is valid in its own right (e.g. wrong group
            // active, or All Assets).
            return;
        }

        $button = $this->extractNavButton($navHtml, $label);
        $this->assertNull($button, "Category button \"{$label}\" should not be present. ".$message);
    }

    /**
     * The ordered list of category button labels, excluding the leading
     * "All Hardware"/"All Software" aggregate button (which is always
     * rendered first, outside the alphabetically-sorted @foreach).
     *
     * @return list<string>
     */
    private function extractCategoryButtonLabels(string $navHtml): array
    {
        preg_match_all('/<a\s+href="[^"]*"\s+class="[^"]*">\s*(.*?)\s*<\/a>/s', $navHtml, $matches);
        $this->assertNotEmpty($matches[1], 'No buttons found in the category nav row.');

        $labels = array_map(
            fn (string $inner) => trim(preg_replace('/\s+/', ' ', strip_tags($inner))),
            $matches[1]
        );

        // Drop the leading "All ..." aggregate button.
        return array_slice($labels, 1);
    }

    // ------------------------------------------------------------------
    // All Assets page
    // ------------------------------------------------------------------

    public function test_all_assets_page_breadcrumb_title_and_active_button(): void
    {
        $this->createFillerCategories();
        $this->createAssetCategory('Laptop', 'hardware');
        $this->createAssetCategory('Digital Systems', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(['Home', 'Assets'], $this->extractBreadcrumbLabels($html));
        $this->assertSame('Assets', $this->extractTitle($html));

        $this->assertNavButtonActive($html, 'All Assets', true);
        $this->assertNavButtonActive($html, 'Hardware', false);
        $this->assertNavButtonActive($html, 'Software', false);
    }

    // ------------------------------------------------------------------
    // Hardware page
    // ------------------------------------------------------------------

    public function test_hardware_page_breadcrumb_title_and_active_button(): void
    {
        $this->createFillerCategories();
        $this->createAssetCategory('Laptop', 'hardware');
        $this->createAssetCategory('Digital Systems', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'hardware']))
            ->assertOk()
            ->getContent();

        $this->assertSame(['Home', 'Assets', 'Hardware'], $this->extractBreadcrumbLabels($html));
        $this->assertSame('Hardware Assets', $this->extractTitle($html));

        $this->assertNavButtonActive($html, 'Hardware', true);
        $this->assertNavButtonActive($html, 'All Assets', false);
        $this->assertNavButtonActive($html, 'Software', false);
    }

    // ------------------------------------------------------------------
    // Software page
    // ------------------------------------------------------------------

    public function test_software_page_breadcrumb_title_and_active_button(): void
    {
        $this->createFillerCategories();
        $this->createAssetCategory('Laptop', 'hardware');
        $this->createAssetCategory('Digital Systems', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'software']))
            ->assertOk()
            ->getContent();

        $this->assertSame(['Home', 'Assets', 'Software'], $this->extractBreadcrumbLabels($html));
        $this->assertSame('Software Assets', $this->extractTitle($html));

        $this->assertNavButtonActive($html, 'Software', true);
        $this->assertNavButtonActive($html, 'All Assets', false);
        $this->assertNavButtonActive($html, 'Hardware', false);
    }

    // ------------------------------------------------------------------
    // Individual category pages
    // ------------------------------------------------------------------

    public function test_laptop_category_breadcrumb_shows_assets_hardware_laptop_with_hardware_active(): void
    {
        $this->createFillerCategories();
        $laptop = $this->createAssetCategory('Laptop', 'hardware');
        $this->createAssetCategory('Digital Systems', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['category_id' => $laptop->id]))
            ->assertOk()
            ->getContent();

        $this->assertSame(['Home', 'Assets', 'Hardware', 'Laptop'], $this->extractBreadcrumbLabels($html));
        $this->assertSame('Laptop Assets', $this->extractTitle($html));

        // "The same All Assets / Hardware / Software buttons remain
        // visible, with Hardware active."
        $this->assertNavButtonActive($html, 'Hardware', true);
        $this->assertNavButtonActive($html, 'All Assets', false);
        $this->assertNavButtonActive($html, 'Software', false);
    }

    public function test_software_category_breadcrumb_shows_assets_software_category_with_software_active(): void
    {
        $this->createFillerCategories();
        $this->createAssetCategory('Laptop', 'hardware');
        $digitalSystems = $this->createAssetCategory('Digital Systems', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['category_id' => $digitalSystems->id]))
            ->assertOk()
            ->getContent();

        $this->assertSame(['Home', 'Assets', 'Software', 'Digital Systems'], $this->extractBreadcrumbLabels($html));
        $this->assertSame('Digital Systems Assets', $this->extractTitle($html));

        $this->assertNavButtonActive($html, 'Software', true);
        $this->assertNavButtonActive($html, 'All Assets', false);
        $this->assertNavButtonActive($html, 'Hardware', false);
    }

    /**
     * A category that exists but has never been assigned an
     * ers_asset_group (every pre-existing category, until an
     * administrator opts it in) has no virtual-group crumb — the trail
     * stays at "Home > Assets > {category}", and none of the three nav
     * buttons is highlighted as more specifically correct than "All
     * Assets" for it.
     */
    public function test_ungrouped_category_breadcrumb_has_no_virtual_group_segment(): void
    {
        $this->createFillerCategories();
        $legacy = $this->createAssetCategory('Legacy Category');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['category_id' => $legacy->id]))
            ->assertOk()
            ->getContent();

        $this->assertSame(['Home', 'Assets', 'Legacy Category'], $this->extractBreadcrumbLabels($html));
        $this->assertSame('Legacy Category Assets', $this->extractTitle($html));
    }

    // ------------------------------------------------------------------
    // Empty groups have no button
    // ------------------------------------------------------------------

    #[DataProvider('emptyGroupProvider')]
    public function test_empty_group_has_no_button(string $assignedGroup, string $missingButtonLabel): void
    {
        $this->createFillerCategories();
        $this->createAssetCategory('Only Assigned Category', $assignedGroup);

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();

        $this->assertNavButtonAbsent($html, $missingButtonLabel);
        // The other two buttons are unaffected.
        $this->assertNavButtonActive($html, 'All Assets', true);
    }

    public static function emptyGroupProvider(): array
    {
        return [
            'no software categories -> no Software button' => ['hardware', 'Software'],
            'no hardware categories -> no Hardware button' => ['software', 'Hardware'],
        ];
    }

    public function test_both_groups_empty_shows_only_all_assets_button(): void
    {
        $this->createFillerCategories();
        // No category assigned to either group.

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();

        $this->assertNavButtonActive($html, 'All Assets', true);
        $this->assertNavButtonAbsent($html, 'Hardware');
        $this->assertNavButtonAbsent($html, 'Software');
    }

    // ------------------------------------------------------------------
    // Buttons use server-resolved group filters — exact URLs, never
    // hard-coded category names or IDs
    // ------------------------------------------------------------------

    public function test_nav_button_urls_match_the_required_server_resolved_routes(): void
    {
        $this->createFillerCategories();
        $this->createAssetCategory('Laptop', 'hardware');
        $this->createAssetCategory('Digital Systems', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();

        $navHtml = $this->extractNavButtonsHtml($html);

        $this->assertSame(route('hardware.index'), $this->extractNavButton($navHtml, 'All Assets')['href']);
        $this->assertSame(route('hardware.index', ['asset_group' => 'hardware']), $this->extractNavButton($navHtml, 'Hardware')['href']);
        $this->assertSame(route('hardware.index', ['asset_group' => 'software']), $this->extractNavButton($navHtml, 'Software')['href']);
    }

    // ------------------------------------------------------------------
    // Filtering and company scoping remain correct when navigated to via
    // the button URLs
    // ------------------------------------------------------------------

    public function test_hardware_button_url_filters_correctly_and_preserves_company_scoping(): void
    {
        $this->createFillerCategories();
        $hardwareCategory = $this->createAssetCategory('Laptop', 'hardware');
        $softwareCategory = $this->createAssetCategory('Digital Systems', 'software');

        [$companyA, $companyB] = Company::factory()->count(2)->create();

        $hardwareAssetCompanyA = $this->createAssetInCategory($hardwareCategory, $companyA);
        $hardwareAssetCompanyB = $this->createAssetInCategory($hardwareCategory, $companyB);
        $softwareAsset = $this->createAssetInCategory($softwareCategory, $companyA);

        $userInCompanyA = $companyA->users()->save(User::factory()->viewAssets()->make());

        // Exactly the URL the Hardware button renders.
        $hardwareButtonUrl = route('hardware.index', ['asset_group' => 'hardware']);
        $this->assertStringContainsString('asset_group=hardware', $hardwareButtonUrl);

        $this->settings->enableMultipleFullCompanySupport();

        $html = $this->actingAs($userInCompanyA)->get($hardwareButtonUrl)->assertOk()->getContent();
        $this->assertNavButtonActive($html, 'Hardware', true);

        // The underlying datatable (same one the page renders) must only
        // resolve category_id to the Hardware category, and must still
        // scope by company.
        $this->actingAsForApi($userInCompanyA)
            ->getJson(route('api.assets.index', ['category_id' => $hardwareCategory->id]))
            ->assertOk()
            ->assertResponseContainsInRows($hardwareAssetCompanyA, 'asset_tag')
            ->assertResponseDoesNotContainInRows($hardwareAssetCompanyB, 'asset_tag')
            ->assertResponseDoesNotContainInRows($softwareAsset, 'asset_tag');
    }

    // ------------------------------------------------------------------
    // Invalid group/category parameters fail safely to All Assets
    // ------------------------------------------------------------------

    public function test_invalid_asset_group_falls_back_to_all_assets_breadcrumb_title_and_button(): void
    {
        $this->createFillerCategories();
        $this->createAssetCategory('Laptop', 'hardware');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'not-a-real-group']))
            ->assertOk()
            ->getContent();

        $this->assertSame(['Home', 'Assets'], $this->extractBreadcrumbLabels($html));
        $this->assertSame('Assets', $this->extractTitle($html));
        $this->assertNavButtonActive($html, 'All Assets', true);
    }

    public function test_asset_group_with_zero_assigned_categories_falls_back_to_all_assets(): void
    {
        $this->createFillerCategories();
        // 'hardware' is a configured group, but nothing is assigned to it.
        $this->createAssetCategory('Digital Systems', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'hardware']))
            ->assertOk()
            ->getContent();

        $this->assertSame(['Home', 'Assets'], $this->extractBreadcrumbLabels($html));
        $this->assertSame('Assets', $this->extractTitle($html));
        $this->assertNavButtonActive($html, 'All Assets', true);
        $this->assertNavButtonAbsent($html, 'Hardware');
    }

    public function test_nonexistent_category_id_falls_back_to_all_assets(): void
    {
        $this->createFillerCategories();
        $this->createAssetCategory('Laptop', 'hardware');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['category_id' => 999999]))
            ->assertOk()
            ->getContent();

        $this->assertSame(['Home', 'Assets'], $this->extractBreadcrumbLabels($html));
        $this->assertSame('Assets', $this->extractTitle($html));
        $this->assertNavButtonActive($html, 'All Assets', true);
    }

    public function test_array_shaped_asset_group_falls_back_to_all_assets_safely(): void
    {
        $this->createFillerCategories();
        $this->createAssetCategory('Laptop', 'hardware');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => ['hardware']]))
            ->assertOk()
            ->getContent();

        $this->assertSame(['Home', 'Assets'], $this->extractBreadcrumbLabels($html));
        $this->assertSame('Assets', $this->extractTitle($html));
        $this->assertNavButtonActive($html, 'All Assets', true);
    }

    /**
     * A malformed/array-shaped status_type must never crash breadcrumb
     * rendering (regression guard for the is_scalar guard in
     * BreadcrumbsServiceProvider::pushHardwareIndexTrailingCrumb()).
     */
    public function test_array_shaped_status_type_does_not_crash_breadcrumb_rendering(): void
    {
        $this->createFillerCategories();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['status_type' => ['Deleted']]))
            ->assertOk();
    }

    // ------------------------------------------------------------------
    // Existing table/toolbar/search/status-filter behavior is preserved
    // ------------------------------------------------------------------

    public function test_asset_table_and_model_column_are_preserved_alongside_the_new_nav(): void
    {
        $this->createFillerCategories();
        $this->createAssetCategory('Laptop', 'hardware');
        $this->createAssetCategory('Digital Systems', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'hardware']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/"field"\s*:\s*"model"/', html_entity_decode($html), 'The Model column must remain in the assets table presenter config.');
        $this->assertMatchesRegularExpression('/data-url="[^"]*\/api\/v1\/hardware[^"]*"/', $html, 'The assets datatable must still be wired up.');
    }

    public function test_status_filter_still_works_and_is_independent_of_the_new_nav_buttons(): void
    {
        $this->createFillerCategories();
        $this->createAssetCategory('Laptop', 'hardware');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['status_type' => 'Deployed']))
            ->assertOk()
            ->getContent();

        $this->assertSame(['Home', 'Assets', 'Deployed'], $this->extractBreadcrumbLabels($html));
        // No asset_group/category_id filter was applied, so this is
        // still effectively "All Assets" for nav-button purposes.
        $this->assertNavButtonActive($html, 'All Assets', true);
    }

    public function test_permission_is_still_required_to_view_the_page_and_its_nav(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('hardware.index'))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Second-row category filter buttons
    // ------------------------------------------------------------------

    public function test_hardware_page_displays_all_assigned_hardware_category_buttons(): void
    {
        $this->createFillerCategories();
        $this->createAssetCategory('Laptop', 'hardware');
        $this->createAssetCategory('Desktop', 'hardware');
        $this->createAssetCategory('Monitor', 'hardware');
        $this->createAssetCategory('Phone', 'hardware');
        $this->createAssetCategory('Digital Systems', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'hardware']))
            ->assertOk()
            ->getContent();

        $navHtml = $this->extractCategoryNavHtml($html);
        $this->assertNotNull($navHtml, 'Expected a Hardware Category row.');
        $this->assertStringContainsString('Hardware Category:', $navHtml);

        foreach (['All Hardware', 'Desktop', 'Laptop', 'Monitor', 'Phone'] as $label) {
            $this->assertNotNull($this->extractNavButton($navHtml, $label), "Expected a \"{$label}\" category button.");
        }

        // Software's own category never leaks into the Hardware row.
        $this->assertCategoryButtonAbsent($html, 'Digital Systems');
    }

    public function test_software_page_displays_its_category_buttons(): void
    {
        $this->createFillerCategories();
        $this->createAssetCategory('Laptop', 'hardware');
        $this->createAssetCategory('Digital Systems', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'software']))
            ->assertOk()
            ->getContent();

        $navHtml = $this->extractCategoryNavHtml($html);
        $this->assertNotNull($navHtml, 'Expected a Software Category row.');
        $this->assertStringContainsString('Software Category:', $navHtml);

        $this->assertNotNull($this->extractNavButton($navHtml, 'All Software'));
        $this->assertNotNull($this->extractNavButton($navHtml, 'Digital Systems'));
        $this->assertCategoryButtonAbsent($html, 'Laptop');
    }

    public function test_category_buttons_are_alphabetical(): void
    {
        $this->createFillerCategories();
        // Deliberately created out of alphabetical order.
        $this->createAssetCategory('Phone', 'hardware');
        $this->createAssetCategory('Desktop', 'hardware');
        $this->createAssetCategory('Laptop', 'hardware');
        $this->createAssetCategory('Monitor', 'hardware');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'hardware']))
            ->assertOk()
            ->getContent();

        $labels = $this->extractCategoryButtonLabels($this->extractCategoryNavHtml($html));

        $this->assertSame(['Desktop', 'Laptop', 'Monitor', 'Phone'], $labels);
    }

    /**
     * The category button list must be sourced live from the database —
     * never a hard-coded list of names/IDs baked into the view or
     * controller. Proven by using categories with names the spec never
     * mentions and non-sequential/large IDs (via an explicit 'id' on the
     * factory), and confirming the rendered buttons match exactly what
     * exists in the database, with URLs built from each category's own
     * (non-guessable) id.
     */
    public function test_category_names_and_ids_are_not_hardcoded(): void
    {
        $this->createFillerCategories();
        $zeta = Category::factory()->create([
            'id' => 9001,
            'name' => 'Zeta Peripheral Widget',
            'category_type' => 'asset',
            'ers_asset_group' => 'hardware',
        ]);
        $alpha = Category::factory()->create([
            'id' => 42,
            'name' => 'Alpha Gadget Array',
            'category_type' => 'asset',
            'ers_asset_group' => 'hardware',
        ]);

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'hardware']))
            ->assertOk()
            ->getContent();

        $navHtml = $this->extractCategoryNavHtml($html);

        $this->assertSame(['Alpha Gadget Array', 'Zeta Peripheral Widget'], $this->extractCategoryButtonLabels($navHtml));

        $alphaButton = $this->extractNavButton($navHtml, 'Alpha Gadget Array');
        $zetaButton = $this->extractNavButton($navHtml, 'Zeta Peripheral Widget');

        $this->assertSame(route('hardware.index', ['category_id' => $alpha->id]), $alphaButton['href']);
        $this->assertSame(route('hardware.index', ['category_id' => $zeta->id]), $zetaButton['href']);
        $this->assertStringContainsString('category_id=42', $alphaButton['href']);
        $this->assertStringContainsString('category_id=9001', $zetaButton['href']);
    }

    public function test_laptop_button_filters_only_laptop_assets(): void
    {
        $laptop = $this->createAssetCategory('Laptop', 'hardware');
        $desktop = $this->createAssetCategory('Desktop', 'hardware');

        $laptopAsset = $this->createAssetInCategory($laptop);
        $desktopAsset = $this->createAssetInCategory($desktop);

        $user = User::factory()->superuser()->create();

        $html = $this->actingAs($user)
            ->get(route('hardware.index', ['asset_group' => 'hardware']))
            ->assertOk()
            ->getContent();

        $laptopButton = $this->extractNavButton($this->extractCategoryNavHtml($html), 'Laptop');
        $this->assertSame(route('hardware.index', ['category_id' => $laptop->id]), $laptopButton['href']);

        $this->actingAsForApi($user)
            ->getJson(route('api.assets.index', ['category_id' => $laptop->id]))
            ->assertOk()
            ->assertResponseContainsInRows($laptopAsset, 'asset_tag')
            ->assertResponseDoesNotContainInRows($desktopAsset, 'asset_tag');
    }

    public function test_selected_category_button_is_active(): void
    {
        $laptop = $this->createAssetCategory('Laptop', 'hardware');
        $this->createAssetCategory('Desktop', 'hardware');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['category_id' => $laptop->id]))
            ->assertOk()
            ->getContent();

        $this->assertCategoryButtonActive($html, 'Laptop', true);
        $this->assertCategoryButtonActive($html, 'Desktop', false);
        $this->assertCategoryButtonActive($html, 'All Hardware', false);

        // First row's Hardware button remains active too.
        $this->assertNavButtonActive($html, 'Hardware', true);
    }

    public function test_all_hardware_and_all_software_active_states_work(): void
    {
        $this->createAssetCategory('Laptop', 'hardware');
        $this->createAssetCategory('Digital Systems', 'software');

        $user = User::factory()->superuser()->create();

        $hardwareHtml = $this->actingAs($user)
            ->get(route('hardware.index', ['asset_group' => 'hardware']))
            ->assertOk()
            ->getContent();
        $this->assertCategoryButtonActive($hardwareHtml, 'All Hardware', true);
        $this->assertCategoryButtonActive($hardwareHtml, 'Laptop', false);

        $softwareHtml = $this->actingAs($user)
            ->get(route('hardware.index', ['asset_group' => 'software']))
            ->assertOk()
            ->getContent();
        $this->assertCategoryButtonActive($softwareHtml, 'All Software', true);
        $this->assertCategoryButtonActive($softwareHtml, 'Digital Systems', false);
    }

    public function test_no_second_row_on_all_assets(): void
    {
        $this->createFillerCategories();
        $this->createAssetCategory('Laptop', 'hardware');
        $this->createAssetCategory('Digital Systems', 'software');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index'))
            ->assertOk()
            ->getContent();

        $this->assertCategoryRowAbsent($html);
    }

    public function test_categories_from_another_group_never_appear(): void
    {
        $this->createFillerCategories();
        $this->createAssetCategory('Laptop', 'hardware');
        $this->createAssetCategory('Desktop', 'hardware');
        $this->createAssetCategory('Digital Systems', 'software');

        $hardwareHtml = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'hardware']))
            ->assertOk()
            ->getContent();

        $this->assertCategoryButtonAbsent($hardwareHtml, 'Digital Systems');

        $softwareHtml = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'software']))
            ->assertOk()
            ->getContent();

        $this->assertCategoryButtonAbsent($softwareHtml, 'Laptop');
        $this->assertCategoryButtonAbsent($softwareHtml, 'Desktop');

        // Sanity: Digital Systems does actually belong to Software.
        $this->assertNotNull($this->extractNavButton($this->extractCategoryNavHtml($softwareHtml), 'Digital Systems'));
    }

    public function test_ungrouped_categories_never_appear_in_category_row(): void
    {
        $this->createFillerCategories();
        $this->createAssetCategory('Laptop', 'hardware');
        $this->createAssetCategory('Legacy Category');

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_group' => 'hardware']))
            ->assertOk()
            ->getContent();

        $this->assertCategoryButtonAbsent($html, 'Legacy Category');
    }

    public function test_category_row_company_scoping_remains_enforced(): void
    {
        $laptop = $this->createAssetCategory('Laptop', 'hardware');
        [$companyA, $companyB] = Company::factory()->count(2)->create();

        $assetCompanyA = $this->createAssetInCategory($laptop, $companyA);
        $assetCompanyB = $this->createAssetInCategory($laptop, $companyB);

        $userInCompanyA = $companyA->users()->save(User::factory()->viewAssets()->make());

        $this->settings->enableMultipleFullCompanySupport();

        $laptopButtonUrl = route('hardware.index', ['category_id' => $laptop->id]);

        $html = $this->actingAs($userInCompanyA)->get($laptopButtonUrl)->assertOk()->getContent();
        $this->assertCategoryButtonActive($html, 'Laptop', true);

        $this->actingAsForApi($userInCompanyA)
            ->getJson(route('api.assets.index', ['category_id' => $laptop->id]))
            ->assertOk()
            ->assertResponseContainsInRows($assetCompanyA, 'asset_tag')
            ->assertResponseDoesNotContainInRows($assetCompanyB, 'asset_tag');
    }

    /**
     * A user with no asset-viewing permission at all is still forbidden
     * from a filtered category-button URL, exactly as for the bare page
     * (see test_permission_is_still_required_to_view_the_page_and_its_nav
     * above) — the new second-row buttons don't open up any new access.
     */
    public function test_category_filtered_url_still_requires_permission(): void
    {
        $laptop = $this->createAssetCategory('Laptop', 'hardware');

        $this->actingAs(User::factory()->create())
            ->get(route('hardware.index', ['category_id' => $laptop->id]))
            ->assertForbidden();
    }
}
