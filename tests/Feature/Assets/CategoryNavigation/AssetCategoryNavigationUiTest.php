<?php

namespace Tests\Feature\Assets\CategoryNavigation;

use App\Models\Category;
use App\Models\User;
use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sidebar tree, page title, breadcrumbs and table wiring on the existing
 * Assets list page (hardware.index).
 */
class AssetCategoryNavigationUiTest extends TestCase
{
    use BuildsAssetCategoryFixture;

    private const NODE = 'ers-asset-category';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
    }

    private function page(array $query = [], ?User $user = null): HtmlPage
    {
        $response = $this->actingAs($user ?? $this->superUser())
            ->get(route('hardware.index', $query))
            ->assertOk();

        return new HtmlPage($response->getContent());
    }

    private function node(HtmlPage $page, Category $category): ?DOMElement
    {
        return $page->first('//li['.HtmlPage::hasClass(self::NODE).' and @data-asset-category="'.$category->id.'"]');
    }

    private function classes(?DOMElement $li): array
    {
        return $li ? preg_split('/\s+/', trim($li->getAttribute('class'))) : [];
    }

    /** Ids of the direct child nodes under a node, or of the root nodes. */
    private function childIds(HtmlPage $page, ?Category $parent = null): array
    {
        $xpath = $parent
            ? '//ul[@id="ers-asset-category-'.$parent->id.'"]/li['.HtmlPage::hasClass(self::NODE).']'
            : '//li['.HtmlPage::hasClass(self::NODE).' and not(ancestor::li['.HtmlPage::hasClass(self::NODE).'])]';

        return array_map(fn (DOMElement $li) => (int) $li->getAttribute('data-asset-category'), $page->all($xpath));
    }

    private function title(HtmlPage $page): string
    {
        return HtmlPage::text($page->first('//title'));
    }

    private function tableApiUrl(HtmlPage $page): string
    {
        return html_entity_decode((string) $page->first('//*[@data-url and contains(@data-url, "/api/v1/hardware")]')?->getAttribute('data-url'));
    }

    /** @return list<string> breadcrumb labels after the home icon */
    private function crumbs(HtmlPage $page): array
    {
        return array_slice(array_map(fn ($li) => HtmlPage::text($li), $page->all('//li['.HtmlPage::hasClass('breadcrumb-item').']')), 1);
    }

    private function listAllIsActive(HtmlPage $page): bool
    {
        // Scoped to the sidebar: the top navbar also has an Assets link.
        return $page->count('//ul['.HtmlPage::hasClass('sidebar-menu').']//li['.HtmlPage::hasClass('active').']/a[@href="'.url('hardware').'"]') === 1;
    }

    // ---------------------------------------------------------------
    // Sidebar tree
    // ---------------------------------------------------------------

    #[Test]
    public function the_sidebar_renders_the_live_tree_in_sort_order_then_name(): void
    {
        $page = $this->page();

        // Roots: groupB (sort 0), groupA (sort 1), ungrouped (sort 5); the
        // empty group (sort 2) is hidden.
        $this->assertSame([$this->cat['groupB']->id, $this->cat['groupA']->id, $this->cat['ungrouped']->id], $this->childIds($page));

        // groupA children both have sort_order 0, so they are ordered by name.
        $expected = collect([$this->cat['branch'], $this->cat['directFinal']])
            ->sortBy(fn ($c) => mb_strtolower($c->name))->pluck('id')->values()->all();
        $this->assertSame($expected, $this->childIds($page, $this->cat['groupA']));

        $this->assertSame([$this->cat['leaf1']->id, $this->cat['leaf2']->id], $this->childIds($page, $this->cat['branch']));
        $this->assertSame([$this->cat['otherFinal']->id], $this->childIds($page, $this->cat['groupB']));

        foreach (['groupA', 'branch', 'leaf1', 'leaf2', 'directFinal', 'groupB', 'otherFinal', 'ungrouped'] as $key) {
            $link = $page->first('a', $this->node($page, $this->cat[$key]));
            $this->assertSame(route('hardware.index', ['asset_category' => $this->cat[$key]->id]), $link->getAttribute('href'), $key);
            $this->assertStringContainsString($this->cat[$key]->name, HtmlPage::text($link), $key);
        }
    }

    #[Test]
    public function reordering_changes_the_rendered_order(): void
    {
        $this->cat['directFinal']->sort_order = 0;
        $this->cat['directFinal']->save();
        $this->cat['branch']->sort_order = 9;
        $this->cat['branch']->save();

        $this->assertSame([$this->cat['directFinal']->id, $this->cat['branch']->id], $this->childIds($this->page(), $this->cat['groupA']));
    }

    #[Test]
    public function deleted_non_asset_and_empty_categories_are_hidden(): void
    {
        $page = $this->page();

        foreach (['deletedFinal', 'accessoryCategory', 'emptyGroup', 'emptyChild'] as $key) {
            $this->assertNull($this->node($page, $this->cat[$key]), $key);
        }
        $this->assertNotNull($this->node($page, $this->cat['groupA']));
    }

    #[Test]
    public function a_group_becomes_visible_once_it_gets_an_assignable_descendant(): void
    {
        $this->finalCategory($this->randomName('New final'), $this->cat['emptyChild']);

        $page = $this->page();

        $this->assertNotNull($this->node($page, $this->cat['emptyGroup']));
        $this->assertNotNull($this->node($page, $this->cat['emptyChild']));
    }

    #[Test]
    public function the_sidebar_supports_the_maximum_depth(): void
    {
        $chain = $this->groupChain(7, $this->randomName('Deep'));
        $leaf = $this->finalCategory($this->randomName('Deep leaf'), $chain[6]);

        $page = $this->page(['asset_category' => $leaf->id]);

        $node = $this->node($page, $leaf);
        $this->assertNotNull($node);
        $this->assertSame('8', $node->getAttribute('data-depth'));
        $this->assertCount(7, $page->all('ancestor::li['.HtmlPage::hasClass(self::NODE).']', $node));
        foreach ($chain as $ancestor) {
            $this->assertContains('active', $this->classes($this->node($page, $ancestor)));
        }
    }

    #[Test]
    public function active_states_follow_the_selected_final_category(): void
    {
        $page = $this->page(['asset_category' => $this->cat['leaf2']->id]);

        $leaf2 = $this->node($page, $this->cat['leaf2']);
        $this->assertContains('active', $this->classes($leaf2));
        $this->assertContains('ers-asset-category-selected', $this->classes($leaf2));
        $this->assertSame('page', $leaf2->getAttribute('aria-current'));

        foreach (['groupA', 'branch'] as $ancestor) {
            $li = $this->node($page, $this->cat[$ancestor]);
            $this->assertContains('active', $this->classes($li), $ancestor);
            $this->assertNotContains('ers-asset-category-selected', $this->classes($li), $ancestor);
            $this->assertFalse($li->hasAttribute('aria-current'), $ancestor);
            $this->assertSame('true', $page->first('button', $li)->getAttribute('aria-expanded'), $ancestor);
        }

        foreach (['leaf1', 'directFinal', 'groupB', 'otherFinal', 'ungrouped'] as $other) {
            $this->assertNotContains('active', $this->classes($this->node($page, $this->cat[$other])), $other);
        }
        $this->assertSame('false', $page->first('button', $this->node($page, $this->cat['groupB']))->getAttribute('aria-expanded'));

        // "List All" is not highlighted while a category is selected; the
        // Assets menu itself stays open.
        $this->assertFalse($this->listAllIsActive($page));
        $this->assertSame(1, $page->count('//li['.HtmlPage::hasClass('treeview').' and '.HtmlPage::hasClass('active').']//li[@data-asset-category="'.$this->cat['leaf2']->id.'"]'));
    }

    #[Test]
    public function selecting_a_group_highlights_and_opens_it(): void
    {
        $page = $this->page(['asset_category' => $this->cat['branch']->id]);

        $branch = $this->node($page, $this->cat['branch']);
        $this->assertContains('ers-asset-category-selected', $this->classes($branch));
        $this->assertSame('true', $page->first('button', $branch)->getAttribute('aria-expanded'));
        $this->assertContains('active', $this->classes($this->node($page, $this->cat['groupA'])));
        $this->assertNotContains('active', $this->classes($this->node($page, $this->cat['leaf1'])));
    }

    #[Test]
    public function the_plain_page_highlights_list_all_and_no_category(): void
    {
        $page = $this->page();

        $this->assertTrue($this->listAllIsActive($page));
        $this->assertSame(0, $page->count('//li['.HtmlPage::hasClass(self::NODE).' and '.HtmlPage::hasClass('active').']'));
    }

    #[Test]
    public function group_nodes_are_plain_links_not_adminlte_treeview_toggles(): void
    {
        $page = $this->page();
        $group = $this->node($page, $this->cat['groupA']);

        $this->assertNotContains('treeview', $this->classes($group));
        $this->assertSame(route('hardware.index', ['asset_category' => $this->cat['groupA']->id]), $page->first('a', $group)->getAttribute('href'));
    }

    #[Test]
    public function asset_viewers_see_the_tree_and_others_do_not(): void
    {
        $viewer = $this->grantAssetCategoryView(User::factory()->viewAssets()->create(), $this->cat['leaf1']);
        $page = new HtmlPage($this->actingAs($viewer)->get(route('profile'))->assertOk()->getContent());
        $this->assertNotNull($this->node($page, $this->cat['leaf1']));
        $this->assertSame(0, $page->count('//li['.HtmlPage::hasClass(self::NODE).' and '.HtmlPage::hasClass('active').']'));

        $nobody = User::factory()->create();
        $this->assertStringNotContainsString('data-asset-category=', $this->actingAs($nobody)->get(route('profile'))->assertOk()->getContent());
        $this->actingAs($nobody)->get(route('hardware.index', ['asset_category' => $this->cat['groupA']->id]))->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Title, breadcrumbs and the table's API url
    // ---------------------------------------------------------------

    #[Test]
    public function the_plain_page_is_unchanged(): void
    {
        $page = $this->page();

        $this->assertStringContainsString(trans('general.all').' '.trans('general.assets'), $this->title($page));
        $this->assertSame([trans('general.assets')], $this->crumbs($page));
        $this->assertStringNotContainsString('asset_category', $this->tableApiUrl($page));
    }

    #[Test]
    public function a_final_category_shows_its_full_path_in_title_and_breadcrumbs(): void
    {
        $page = $this->page(['asset_category' => $this->cat['leaf1']->id]);

        $this->assertStringContainsString(
            $this->cat['groupA']->name.' > '.$this->cat['branch']->name.' > '.$this->cat['leaf1']->name,
            $this->title($page)
        );
        $this->assertSame(
            [trans('general.assets'), $this->cat['groupA']->name, $this->cat['branch']->name, $this->cat['leaf1']->name],
            $this->crumbs($page)
        );
        // Ancestor crumbs link to their own node.
        $this->assertSame(1, $page->count('//li['.HtmlPage::hasClass('breadcrumb-item').']/a[@href="'.route('hardware.index', ['asset_category' => $this->cat['branch']->id]).'"]'));

        $this->assertStringContainsString('asset_category='.$this->cat['leaf1']->id, $this->tableApiUrl($page));
    }

    #[Test]
    public function a_navigation_group_shows_its_path_and_passes_only_its_node_id(): void
    {
        $page = $this->page(['asset_category' => $this->cat['branch']->id]);

        $this->assertStringContainsString($this->cat['groupA']->name.' > '.$this->cat['branch']->name, $this->title($page));
        $this->assertSame([trans('general.assets'), $this->cat['groupA']->name, $this->cat['branch']->name], $this->crumbs($page));

        $url = $this->tableApiUrl($page);
        $this->assertStringContainsString('asset_category='.$this->cat['branch']->id, $url);
        // Only the node id reaches the API; descendants are resolved there.
        $this->assertStringNotContainsString(',', $url);
        $this->assertStringNotContainsString('category_id', $url);
    }

    #[Test]
    public function status_filters_combine_with_the_category(): void
    {
        $page = $this->page(['asset_category' => $this->cat['leaf1']->id, 'status_type' => 'Deployed']);

        $url = $this->tableApiUrl($page);
        $this->assertStringContainsString('asset_category='.$this->cat['leaf1']->id, $url);
        $this->assertStringContainsString('status_type=Deployed', $url);

        // (The status crumb is chosen when the app boots, before a test
        // request exists, so the status is asserted through the title.)
        $this->assertStringContainsString(trans('general.deployed'), $this->title($page));
        $this->assertStringContainsString($this->cat['leaf1']->name, $this->title($page));
        $this->assertContains($this->cat['leaf1']->name, $this->crumbs($page));
    }

    public static function invalidQueries(): array
    {
        return [
            'text' => [['asset_category' => 'laptops']],
            'comma separated' => [['asset_category' => '__LIST__']],
            'array' => [['asset_category' => ['__ID__']]],
            'nested array' => [['asset_category' => ['x' => ['__ID__']]]],
            'negative' => [['asset_category' => '-5']],
            'empty' => [['asset_category' => '']],
            'missing' => [['asset_category' => '99999999']],
            'deleted' => [['asset_category' => '__DELETED__']],
            'non-asset' => [['asset_category' => '__ACCESSORY__']],
        ];
    }

    #[Test]
    #[DataProvider('invalidQueries')]
    public function invalid_filters_fall_back_to_the_normal_page(array $query): void
    {
        array_walk_recursive($query, function (&$value) {
            $value = strtr($value, [
                '__LIST__' => $this->cat['leaf1']->id.','.$this->cat['leaf2']->id,
                '__ID__' => (string) $this->cat['leaf1']->id,
                '__DELETED__' => (string) $this->cat['deletedFinal']->id,
                '__ACCESSORY__' => (string) $this->cat['accessoryCategory']->id,
            ]);
        });

        $page = $this->page($query);

        $this->assertStringContainsString(trans('general.all').' '.trans('general.assets'), $this->title($page));
        $this->assertSame([trans('general.assets')], $this->crumbs($page));
        $this->assertStringNotContainsString('asset_category', $this->tableApiUrl($page));
        $this->assertSame(0, $page->count('//li['.HtmlPage::hasClass(self::NODE).' and '.HtmlPage::hasClass('active').']'));
        $this->assertTrue($this->listAllIsActive($page));
    }

    #[Test]
    public function category_names_are_escaped_everywhere(): void
    {
        $evil = $this->finalCategory('<script>alert(1)</script> "q" & <b>y</b>', $this->cat['groupA']);
        $this->assetIn($evil);

        $html = $this->actingAs($this->superUser())->get(route('hardware.index', ['asset_category' => $evil->id]))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>y</b>', $html);
        // Sidebar link, title, breadcrumb: all escaped text.
        $this->assertGreaterThanOrEqual(3, substr_count($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));
    }
}
