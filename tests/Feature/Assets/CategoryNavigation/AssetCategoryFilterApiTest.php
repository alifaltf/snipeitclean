<?php

namespace Tests\Feature\Assets\CategoryNavigation;

use App\Models\Asset;
use App\Models\Company;
use App\Models\Statuslabel;
use App\Models\User;
use App\Services\AssetCategorySelection;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ?asset_category=<id> on the existing /api/v1/hardware endpoint.
 */
class AssetCategoryFilterApiTest extends TestCase
{
    use BuildsAssetCategoryFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
    }

    private function index(array $query = [], ?User $user = null): TestResponse
    {
        return $this->actingAsForApi($user ?? $this->superUser())
            ->getJson(route('api.assets.index', $query))
            ->assertOk();
    }

    private function ids(TestResponse $response): array
    {
        return collect($response->json('rows'))->pluck('id')->sort()->values()->all();
    }

    private function allIds(): array
    {
        return $this->assetIds(['leaf1', 'leaf2', 'directFinal', 'otherFinal', 'ungrouped']);
    }

    #[Test]
    public function without_a_category_the_list_is_unfiltered(): void
    {
        $this->assertSame($this->allIds(), $this->ids($this->index()));
    }

    #[Test]
    public function a_final_category_returns_only_its_own_assets(): void
    {
        $this->assertSame($this->assetIds(['leaf1']), $this->ids($this->index(['asset_category' => $this->cat['leaf1']->id])));
        // A sibling final category does not leak in.
        $this->assertSame($this->assetIds(['leaf2']), $this->ids($this->index(['asset_category' => $this->cat['leaf2']->id])));
        $this->assertSame($this->assetIds(['ungrouped']), $this->ids($this->index(['asset_category' => $this->cat['ungrouped']->id])));
    }

    #[Test]
    public function a_navigation_group_returns_assets_from_all_assignable_descendants_only(): void
    {
        // groupA covers leaf1 + leaf2 (two levels down) and directFinal; not
        // the unrelated branch (groupB) and not the ungrouped root category.
        $this->assertSame(
            $this->assetIds(['leaf1', 'leaf2', 'directFinal']),
            $this->ids($this->index(['asset_category' => $this->cat['groupA']->id]))
        );

        $this->assertSame(
            $this->assetIds(['leaf1', 'leaf2']),
            $this->ids($this->index(['asset_category' => $this->cat['branch']->id]))
        );

        $this->assertSame($this->assetIds(['otherFinal']), $this->ids($this->index(['asset_category' => $this->cat['groupB']->id])));
    }

    #[Test]
    public function a_group_without_assignable_descendants_matches_nothing(): void
    {
        $response = $this->index(['asset_category' => $this->cat['emptyGroup']->id]);

        $this->assertSame([], $this->ids($response));
        $this->assertSame(0, $response->json('total'));
    }

    public static function invalidValues(): array
    {
        return [
            'empty' => [''],
            'text' => ['laptops'],
            'comma separated' => ['__IDS__'],
            'negative' => ['-1'],
            'zero' => ['0'],
            'decimal' => ['1.0'],
            'padded' => [' 1'],
            'trailing newline' => ["1\n"],
            'hex' => ['0x1'],
            'overflowing' => ['99999999999999999999999'],
            'missing id' => ['987654321'],
            'array' => [['__ID__']],
            'keyed array' => [['a' => '__ID__']],
        ];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function invalid_values_fall_back_to_the_unfiltered_list(mixed $value): void
    {
        $leafId = (string) $this->cat['leaf1']->id;
        $value = is_array($value)
            ? array_map(fn ($v) => $v === '__ID__' ? $leafId : $v, $value)
            : str_replace(['__IDS__', '__ID__'], [$leafId.','.$this->cat['leaf2']->id, $leafId], $value);

        $this->assertSame($this->allIds(), $this->ids($this->index(['asset_category' => $value])));
    }

    #[Test]
    public function deleted_and_non_asset_categories_fall_back_to_the_unfiltered_list(): void
    {
        foreach (['deletedFinal', 'accessoryCategory'] as $key) {
            $this->assertSame($this->allIds(), $this->ids($this->index(['asset_category' => $this->cat[$key]->id])), $key);
        }
    }

    #[Test]
    public function the_filter_combines_with_status_filters(): void
    {
        $archived = Statuslabel::factory()->archived()->create();
        $archivedLeafAsset = $this->assetIn($this->cat['leaf1'], ['status_id' => $archived->id]);

        // Default list hides archived assets; the category filter respects that.
        $this->assertSame($this->assetIds(['leaf1']), $this->ids($this->index(['asset_category' => $this->cat['leaf1']->id])));

        // Archived + category: only the archived asset of that category.
        $this->assertSame(
            [$archivedLeafAsset->id],
            $this->ids($this->index(['asset_category' => $this->cat['groupA']->id, 'status_type' => 'Archived']))
        );

        $this->assertSame([], $this->ids($this->index(['asset_category' => $this->cat['groupB']->id, 'status_type' => 'Archived'])));
    }

    #[Test]
    public function search_sort_and_pagination_still_work_with_the_filter(): void
    {
        $extra = $this->assetIn($this->cat['leaf2'], ['name' => 'Findable '.$this->randomName('x')]);
        $group = $this->cat['groupA']->id;

        $this->assertSame([$extra->id], $this->ids($this->index(['asset_category' => $group, 'search' => 'Findable'])));

        $page = $this->index(['asset_category' => $group, 'limit' => 2, 'offset' => 0, 'sort' => 'id', 'order' => 'asc']);
        $this->assertSame(4, $page->json('total'));
        $this->assertCount(2, $page->json('rows'));

        $next = $this->index(['asset_category' => $group, 'limit' => 2, 'offset' => 2, 'sort' => 'id', 'order' => 'asc']);
        $this->assertCount(2, $next->json('rows'));
        $this->assertEmpty(array_intersect($this->ids($page), $this->ids($next)));

        // Sorting by category (which joins categories) still works with the filter.
        $this->index(['asset_category' => $group, 'sort' => 'category', 'order' => 'desc']);
        $this->index(['asset_category' => $group, 'sort' => 'model', 'order' => 'asc']);
    }

    #[Test]
    public function company_scoping_still_applies(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $inA = $this->assetIn($this->cat['leaf1'], ['company_id' => $companyA->id]);
        $inB = $this->assetIn($this->cat['leaf1'], ['company_id' => $companyB->id]);
        $userInA = $companyA->users()->save(User::factory()->viewAssets()->make());
        $userInA = $this->grantAssetCategoryView($userInA, $this->cat['leaf1']);

        $this->settings->enableMultipleFullCompanySupport();

        $ids = $this->ids($this->index(['asset_category' => $this->cat['groupA']->id], $userInA));
        $this->assertContains($inA->id, $ids);
        $this->assertNotContains($inB->id, $ids);
    }

    #[Test]
    public function asset_permissions_are_still_required(): void
    {
        $this->actingAsForApi(User::factory()->create())
            ->getJson(route('api.assets.index', ['asset_category' => $this->cat['groupA']->id]))
            ->assertForbidden();
    }

    #[Test]
    public function the_resolver_expands_groups_server_side_and_rejects_everything_else(): void
    {
        $selection = AssetCategorySelection::fromValue((string) $this->cat['groupA']->id);
        $this->assertTrue($selection->isNavigationGroup());
        $this->assertEqualsCanonicalizing(
            [$this->cat['leaf1']->id, $this->cat['leaf2']->id, $this->cat['directFinal']->id],
            $selection->assignableIds
        );
        $this->assertSame([$this->cat['groupA']->name], $selection->pathNames());

        $leaf = AssetCategorySelection::fromValue($this->cat['leaf2']->id);
        $this->assertFalse($leaf->isNavigationGroup());
        $this->assertSame([$this->cat['leaf2']->id], $leaf->assignableIds);
        $this->assertSame([$this->cat['groupA']->id, $this->cat['branch']->id], $leaf->ancestorIds());

        foreach ([null, true, 1.0, [], [$this->cat['leaf1']->id], $this->cat['leaf1']->id.','.$this->cat['leaf2']->id, $this->cat['deletedFinal']->id, $this->cat['accessoryCategory']->id] as $bad) {
            $this->assertNull(AssetCategorySelection::fromValue($bad), var_export($bad, true));
        }
    }

    #[Test]
    public function the_deepest_allowed_level_is_filterable_through_every_ancestor(): void
    {
        $chain = $this->groupChain(7, $this->randomName('Deep'));
        $deepLeaf = $this->finalCategory($this->randomName('Deep leaf'), $chain[6]);
        $deepAsset = $this->assetIn($deepLeaf);

        $this->assertSame(8, AssetCategorySelection::fromValue($deepLeaf->id)->node->depth);
        $this->assertSame([$deepAsset->id], $this->ids($this->index(['asset_category' => $deepLeaf->id])));
        $this->assertSame([$deepAsset->id], $this->ids($this->index(['asset_category' => $chain[0]->id])));
        $this->assertSame([$deepAsset->id], $this->ids($this->index(['asset_category' => $chain[5]->id])));
    }

    #[Test]
    public function the_filter_uses_only_the_resolved_ids_even_with_the_legacy_param_present(): void
    {
        // category_id is the pre-existing (unchanged) filter; asset_category
        // narrows further and never widens.
        $response = $this->index([
            'asset_category' => $this->cat['leaf1']->id,
            'category_id' => $this->cat['leaf1']->id.','.$this->cat['otherFinal']->id,
        ]);

        $this->assertSame($this->assetIds(['leaf1']), $this->ids($response));
    }
}
