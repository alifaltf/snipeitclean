<?php

namespace Tests\Feature\Assets\CategoryPermissions;

use App\Models\Asset;
use App\Models\Group;
use App\Models\Maintenance;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Assets\CategoryNavigation\HtmlPage;
use Tests\TestCase;

/**
 * ERS Phase 5B1: asset-category View enforcement in the web UI, sidebar,
 * dashboard, reports, exports and related records. (Account pages are
 * covered by AssetCategoryViewAccountTest.)
 */
class AssetCategoryViewUiTest extends TestCase
{
    use BuildsViewEnforcementFixture;

    private const NODE = 'ers-asset-category';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAssets();
    }

    /** @return list<int> category ids rendered as sidebar nodes */
    private function sidebarNodeIds(string $html): array
    {
        $page = new HtmlPage($html);

        return array_map(
            fn ($li) => (int) $li->getAttribute('data-asset-category'),
            $page->all('//li['.HtmlPage::hasClass(self::NODE).']')
        );
    }

    private function assertHiddenFromHtml(string $html, string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->assertStringNotContainsString($this->cat[$key]->name, $html, "Category name leaked: {$key}");
            if (isset($this->asset[$key])) {
                $this->assertStringNotContainsString($this->asset[$key]->asset_tag, $html, "Asset tag leaked: {$key}");
            }
        }
    }

    // ---------------------------------------------------------------
    // Navigation
    // ---------------------------------------------------------------

    #[Test]
    public function the_sidebar_only_shows_authorised_categories_and_their_ancestors(): void
    {
        $user = $this->viewer([['leaf2']]);
        $html = $this->actingAs($user)->get(route('hardware.index'))->assertOk()->getContent();

        $this->assertEqualsCanonicalizing(
            [$this->cat['groupA']->id, $this->cat['branch']->id, $this->cat['leaf2']->id],
            $this->sidebarNodeIds($html)
        );
        $this->assertHiddenFromHtml($html, 'leaf1', 'direct', 'groupB', 'other', 'loose', 'emptyGroup');
    }

    #[Test]
    public function super_admin_navigation_is_unchanged(): void
    {
        $html = $this->actingAs($this->superUser())->get(route('hardware.index'))->assertOk()->getContent();

        foreach (['groupA', 'branch', 'leaf1', 'leaf2', 'direct', 'groupB', 'other', 'loose'] as $key) {
            $this->assertContains($this->cat[$key]->id, $this->sidebarNodeIds($html), $key);
        }
    }

    #[Test]
    public function a_user_with_no_authorised_categories_sees_no_category_navigation(): void
    {
        $html = $this->actingAs($this->viewer([]))->get(route('hardware.index'))->assertOk()->getContent();

        $this->assertSame([], $this->sidebarNodeIds($html));
        $this->assertHiddenFromHtml($html, 'groupA', 'branch', 'leaf1', 'leaf2', 'direct', 'groupB', 'other', 'loose');
    }

    #[Test]
    public function selecting_an_unauthorised_node_does_not_leak_its_name_or_path(): void
    {
        $user = $this->viewer([['leaf1']]);

        foreach (['groupB', 'other', 'loose'] as $key) {
            $html = $this->actingAs($user)->get(route('hardware.index', ['asset_category' => $this->cat[$key]->id]))->assertOk()->getContent();
            $this->assertHiddenFromHtml($html, $key);
        }

        // An authorised group narrows to the granted descendants only.
        $html = $this->actingAs($user)->get(route('hardware.index', ['asset_category' => $this->cat['groupA']->id]))->assertOk()->getContent();
        $this->assertStringContainsString($this->cat['groupA']->name, $html);
        $this->assertHiddenFromHtml($html, 'leaf2', 'direct');
    }

    // ---------------------------------------------------------------
    // Direct URLs
    // ---------------------------------------------------------------

    #[Test]
    public function direct_urls_to_unauthorised_assets_are_not_served(): void
    {
        $user = $this->viewer([['leaf1']], ['assets.view' => '1', 'assets.edit' => '1', 'assets.checkout' => '1', 'assets.checkin' => '1']);
        $hidden = $this->asset['other'];

        $this->actingAs($user)->get(route('hardware.show', $this->asset['leaf1']))->assertOk();

        foreach ([
            route('hardware.show', $hidden),
            route('hardware.edit', $hidden),
            route('hardware.checkout.create', $hidden),
            route('clone/hardware', $hidden),
            route('findbytag/hardware', ['any' => $hidden->asset_tag]),
        ] as $url) {
            $response = $this->actingAs($user)->get($url);
            $this->assertNotSame(200, $response->status(), $url);
            $this->assertStringNotContainsString($hidden->asset_tag, $response->getContent(), $url);
            $this->assertHiddenFromHtml((string) $response->getContent(), 'other');
        }
    }

    #[Test]
    public function super_admin_can_open_every_asset(): void
    {
        foreach ($this->asset as $asset) {
            $this->actingAs($this->superUser())->get(route('hardware.show', $asset))->assertOk();
        }
    }

    // ---------------------------------------------------------------
    // Dashboard, reports, exports
    // ---------------------------------------------------------------

    #[Test]
    public function dashboard_asset_total_counts_only_authorised_assets(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('home'))->assertOk()->assertViewHas('counts', fn (array $counts) => $counts['asset'] === 0);

        $this->grantAdmin($admin, ['leaf1', 'other']);
        $this->actingAs($admin->fresh())->get(route('home'))->assertOk()->assertViewHas('counts', fn (array $counts) => $counts['asset'] === 2);

        $this->actingAs($this->superUser())->get(route('home'))->assertOk()->assertViewHas('counts', fn (array $counts) => $counts['asset'] === 5);
    }

    #[Test]
    public function custom_asset_report_export_contains_only_authorised_assets(): void
    {
        $user = $this->viewer([['leaf1', 'loose']], ['assets.view' => '1', 'reports.view' => '1']);

        $response = $this->actingAs($user)->post(route('reports.post-custom'), ['asset_tag' => '1', 'category' => '1'])->assertOk();

        $response->assertSeeTextInStreamedResponse($this->asset['leaf1']->asset_tag);
        $response->assertSeeTextInStreamedResponse($this->asset['loose']->asset_tag);
        foreach (['leaf2', 'direct', 'other'] as $key) {
            $response->assertDontSeeTextInStreamedResponse($this->asset[$key]->asset_tag);
            $response->assertDontSeeTextInStreamedResponse($this->cat[$key]->name);
        }
    }

    #[Test]
    public function maintenances_of_unauthorised_assets_are_hidden(): void
    {
        $visible = Maintenance::factory()->create(['asset_id' => $this->asset['leaf1']->id]);
        $hidden = Maintenance::factory()->create(['asset_id' => $this->asset['other']->id]);
        $user = $this->viewer([['leaf1']], ['assets.view' => '1']);

        $response = $this->actingAsForApi($user)->getJson(route('api.maintenances.index'))->assertOk();
        $ids = collect($response->json('rows'))->pluck('id')->all();

        $this->assertSame([$visible->id], $ids);
        $this->assertSame(1, $response->json('total'));
        $this->assertStringNotContainsString($this->asset['other']->asset_tag, $response->getContent());
        $this->assertNotSame('success', $this->actingAsForApi($user)->getJson(route('api.maintenances.show', $hidden->id))->json('status'));
    }

    private function grantAdmin(User $admin, array $keys): void
    {
        $group = Group::factory()->create(['permissions' => json_encode([])]);
        foreach ($keys as $key) {
            $this->grant($group, $this->cat[$key], ['view']);
        }
        $admin->groups()->attach($group->id);
        $this->flushPermissions();
    }
}
