<?php

namespace Tests\Feature\Assets\CategoryPermissions;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 5B1: for a non-Super-Admin an asset is viewable only when its
 * model exists and is not soft-deleted, and the model belongs to a live,
 * final/assignable ASSET category that is authorised for View (plus global
 * assets.view and company scope). Every other asset is Super-Admin-only.
 * There is no fallback category.
 */
class AssetCategoryViewMissingModelTest extends TestCase
{
    use BuildsViewEnforcementFixture;

    /** @var array<string, Asset> one asset per unresolvable condition */
    private array $broken = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAssets();

        $make = function (string $key, string $category = 'leaf1'): Asset {
            return $this->broken[$key] = $this->assetIn($this->cat[$category], [
                'name' => 'Findable broken '.$key,
                'asset_tag' => $this->randomName('BROKEN-'.$key),
                'serial' => $this->randomName('BSER-'.$key),
            ]);
        };

        // model_id is null.
        DB::table('assets')->where('id', $make('nullModel')->id)->update(['model_id' => null]);

        // Model does not exist (dangling id, and a hard-deleted model).
        DB::table('assets')->where('id', $make('danglingModel')->id)->update(['model_id' => 999999]);
        $make('forceDeletedModel')->model()->forceDelete();

        // Model soft-deleted, while its former category (leaf1) is granted.
        $make('softDeletedModel')->model->delete();

        // Model has no category / category does not exist.
        DB::table('models')->where('id', $make('noCategory')->model_id)->update(['category_id' => null]);
        DB::table('models')->where('id', $make('missingCategory')->model_id)->update(['category_id' => 999999]);

        // Category soft-deleted, non-asset, or navigation-only.
        DB::table('models')->where('id', $make('deletedCategory', 'leaf2')->model_id)->update(['category_id' => $this->cat['deleted']->id]);
        DB::table('models')->where('id', $make('nonAssetCategory', 'leaf2')->model_id)->update(['category_id' => $this->cat['accessory']->id]);
        DB::table('models')->where('id', $make('navigationCategory', 'leaf2')->model_id)->update(['category_id' => $this->cat['groupA']->id]);
    }

    /**
     * Every global asset permission and View grants on every live final
     * category, plus forged grant rows on the navigation group, the
     * soft-deleted category and the non-asset category.
     */
    private function fullyGrantedAdmin(): User
    {
        return $this->viewer(
            [['leaf1', 'leaf2', 'direct', 'other', 'loose'], ['groupA', 'deleted', 'accessory']],
            ['assets.view' => '1', 'assets.edit' => '1', 'assets.checkout' => '1', 'assets.checkin' => '1', 'reports.view' => '1', 'admin' => '1']
        );
    }

    /** @return list<int> */
    private function rowIds(TestResponse $response): array
    {
        return collect($response->json('rows'))->pluck('id')->all();
    }

    private function assertNotRevealed(Asset $asset, TestResponse $response, string $context): void
    {
        $this->assertNotSame('success', $response->json('status'), $context);
        $this->assertStringNotContainsString($asset->asset_tag, (string) $response->getContent(), $context);
        $this->assertStringNotContainsString($asset->serial, (string) $response->getContent(), $context);
    }

    #[Test]
    public function every_unresolvable_asset_is_filtered_from_lists_and_the_api(): void
    {
        $user = $this->fullyGrantedAdmin();

        $response = $this->actingAsForApi($user)->getJson(route('api.assets.index', ['limit' => 100]))->assertOk();

        $this->assertSame(5, $response->json('total'));
        $this->assertEqualsCanonicalizing($this->assetIds(['leaf1', 'leaf2', 'direct', 'other', 'loose']), $this->rowIds($response));
        foreach ($this->broken as $key => $asset) {
            $this->assertStringNotContainsString($asset->asset_tag, $response->getContent(), $key);
        }

        // Filtering by the soft-deleted model or its former category does not bring it back.
        $model = AssetModel::withTrashed()->find($this->broken['softDeletedModel']->model_id);
        $this->assertSame([], $this->rowIds($this->actingAsForApi($user)->getJson(route('api.assets.index', ['model_id' => $model->id]))));
        $this->assertNotContains(
            $this->broken['softDeletedModel']->id,
            $this->rowIds($this->actingAsForApi($user)->getJson(route('api.assets.index', ['category_id' => $this->cat['leaf1']->id, 'deleted' => 'false'])))
        );
    }

    #[Test]
    public function direct_urls_and_api_lookups_do_not_serve_them(): void
    {
        $user = $this->fullyGrantedAdmin();

        foreach ($this->broken as $key => $asset) {
            $this->assertNotRevealed($asset, $this->actingAsForApi($user)->getJson(route('api.assets.show', $asset->id)), "api show {$key}");
            $this->assertNotRevealed($asset, $this->actingAsForApi($user)->getJson(route('api.assets.history', $asset->id)), "api history {$key}");
        }
    }

    #[Test]
    public function the_web_ui_does_not_serve_them(): void
    {
        $user = $this->fullyGrantedAdmin();

        foreach ($this->broken as $key => $asset) {
            foreach ([route('hardware.show', $asset->id), route('hardware.edit', $asset->id), route('findbytag/hardware', ['any' => $asset->asset_tag])] as $url) {
                $response = $this->actingAs($user)->get($url);
                $this->assertNotSame(200, $response->status(), "{$key} {$url}");
                $this->assertStringNotContainsString($asset->asset_tag, (string) $response->getContent(), "{$key} {$url}");
            }
        }
    }

    #[Test]
    public function search_tag_and_serial_lookups_do_not_find_them(): void
    {
        $user = $this->fullyGrantedAdmin();

        foreach ($this->broken as $key => $asset) {
            $this->assertSame([], $this->rowIds($this->actingAsForApi($user)->getJson(route('api.assets.index', ['search' => $asset->asset_tag]))), "search {$key}");
            $this->assertNotRevealed($asset, $this->actingAsForApi($user)->getJson(route('api.assets.show.bytag', ['any' => $asset->asset_tag])), "bytag {$key}");
            $this->assertNotRevealed($asset, $this->actingAsForApi($user)->getJson(route('api.assets.show.byserial', ['any' => $asset->serial])), "byserial {$key}");
            $this->assertNotContains($asset->id, collect($this->actingAsForApi($user)->getJson(route('assets.selectlist', ['search' => $asset->asset_tag]))->json('results'))->pluck('id')->all(), "selectlist {$key}");
        }

        $all = $this->actingAsForApi($user)->getJson(route('api.assets.index', ['search' => 'Findable', 'limit' => 100]));
        $this->assertSame(5, $all->json('total'));
    }

    #[Test]
    public function an_ordinary_user_is_denied_even_when_the_soft_deleted_models_former_category_is_granted(): void
    {
        $asset = $this->broken['softDeletedModel'];
        $user = $this->viewer([['leaf1']]);

        $index = $this->actingAsForApi($user)->getJson(route('api.assets.index'))->assertOk();
        $this->assertSame($this->assetIds(['leaf1']), $this->rowIds($index));
        $this->assertSame(1, $index->json('total'));
        $this->assertNotRevealed($asset, $this->actingAsForApi($user)->getJson(route('api.assets.show', $asset->id)), 'show');
        $this->assertNotRevealed($asset, $this->actingAsForApi($user)->getJson(route('api.assets.show.bytag', ['any' => $asset->asset_tag])), 'bytag');

        // Restoring the model makes the asset visible again: the model's
        // state is the only reason it was hidden.
        AssetModel::withTrashed()->find($asset->model_id)->restore();
        $this->flushPermissions();
        $this->assertContains($asset->id, $this->rowIds($this->actingAsForApi($user)->getJson(route('api.assets.index'))));
    }

    #[Test]
    public function counts_and_pagination_exclude_them(): void
    {
        foreach (range(1, 6) as $i) {
            $this->assetIn($this->cat['leaf1'], ['name' => 'Findable page '.$i])->model->delete();
        }
        $user = $this->fullyGrantedAdmin();

        $first = $this->actingAsForApi($user)->getJson(route('api.assets.index', ['limit' => 2, 'offset' => 0, 'sort' => 'id', 'order' => 'asc']));
        $last = $this->actingAsForApi($user)->getJson(route('api.assets.index', ['limit' => 2, 'offset' => 4, 'sort' => 'id', 'order' => 'asc']));
        $this->assertSame(5, $first->json('total'));
        $this->assertCount(2, $first->json('rows'));
        $this->assertCount(1, $last->json('rows'));

        $picker = $this->actingAsForApi($user)->getJson(route('assets.selectlist', ['search' => 'Findable']));
        $this->assertSame(5, $picker->json('total_count'));
    }

    #[Test]
    public function the_dashboard_total_excludes_them(): void
    {
        $this->actingAs($this->fullyGrantedAdmin())->get(route('home'))->assertOk()->assertViewHas('counts', fn (array $counts) => $counts['asset'] === 5);
        $this->actingAs($this->superUser())->get(route('home'))->assertOk()->assertViewHas('counts', fn (array $counts) => $counts['asset'] === 5 + count($this->broken));
    }

    #[Test]
    public function super_admin_still_sees_them_everywhere(): void
    {
        $admin = $this->superUser();

        $response = $this->actingAsForApi($admin)->getJson(route('api.assets.index', ['limit' => 100]))->assertOk();
        $this->assertSame(5 + count($this->broken), $response->json('total'));

        foreach ($this->broken as $key => $asset) {
            $this->assertContains($asset->id, $this->rowIds($response), $key);
            $this->assertSame($asset->id, $this->actingAsForApi($admin)->getJson(route('api.assets.show', $asset->id))->json('id'), $key);
            $this->assertStringContainsString($asset->asset_tag, $this->actingAsForApi($admin)->getJson(route('api.assets.show.bytag', ['any' => $asset->asset_tag]))->getContent(), $key);
        }
    }

    #[Test]
    public function super_admin_can_open_the_soft_deleted_model_asset_in_the_ui(): void
    {
        $this->actingAs($this->superUser())->get(route('hardware.show', $this->broken['softDeletedModel']->id))->assertOk();
    }
}
