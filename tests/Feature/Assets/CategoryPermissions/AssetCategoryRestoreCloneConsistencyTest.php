<?php

namespace Tests\Feature\Assets\CategoryPermissions;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\User;
use App\Services\AssetCategoryWriteAuthorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 5B2 final correction:
 *  - every restore path and every Restore action use ONE rule (global
 *    assets.delete plus category Delete), independent of assets.edit and
 *    assets.create;
 *  - web bulk edit/delete/restore (and their confirmation pages) validate
 *    the submitted selection before any query and answer malformed input
 *    with the generic bulk_selection_unavailable message, changing nothing;
 *  - a Clone action is only offered when the clone page would accept it.
 */
class AssetCategoryRestoreCloneConsistencyTest extends TestCase
{
    use BuildsWriteEnforcementFixture;

    private const DELETE_ONLY = ['assets.view' => '1', 'assets.delete' => '1'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAssets();
    }

    private function isDeleted(Asset $asset): bool
    {
        return $this->rawAsset($asset)->deleted_at !== null;
    }

    /** available_actions of $asset in the deleted-assets API listing. */
    private function deletedRowActions(User $user, Asset $asset): array
    {
        $rows = collect($this->actingAsForApi($user)->getJson(route('api.assets.index', ['status' => 'Deleted', 'limit' => 500]))->assertOk()->json('rows'));
        $row = $rows->firstWhere('id', $asset->id);
        $this->assertNotNull($row, 'The deleted asset must be listed.');

        return $row['available_actions'];
    }

    /** @return array<int, array> raw rows of every asset, keyed by id */
    private function snapshot(): array
    {
        return Asset::withoutGlobalScopes()->getQuery()->orderBy('id')->get()->keyBy('id')->map(fn ($row) => (array) $row)->all();
    }

    // ---------------------------------------------------------------
    // Restore
    // ---------------------------------------------------------------

    #[Test]
    public function a_non_admin_with_only_global_and_category_delete_sees_and_uses_every_restore_path(): void
    {
        foreach (['leaf1', 'leaf2', 'direct', 'loose'] as $key) {
            $this->asset[$key]->delete();
        }
        $grants = array_fill_keys(['leaf1', 'leaf2', 'direct', 'loose'], ['view', 'delete']);
        $user = $this->writer([$grants], self::DELETE_ONLY);

        $this->assertFalse($user->hasAccess('assets.edit'));
        $this->assertFalse($user->hasAccess('assets.create'));
        $this->assertFalse($user->isAdmin());

        // The UI offers Restore...
        $actions = $this->deletedRowActions($user, $this->asset['leaf1']);
        $this->assertTrue($actions['restore']);
        $this->assertTrue($actions['bulk_selectable']['restore']);
        $this->assertFalse($actions['clone'], 'No global Create, so no Clone.');
        $this->assertStringContainsString(
            route('restore/hardware', ['asset' => $this->asset['leaf1']->id]),
            $this->actingAs($user, 'web')->get(route('hardware.show', $this->asset['leaf1']))->assertOk()->getContent()
        );

        // ...and every restore path accepts it.
        $this->actingAs($user, 'web')->post(route('restore/hardware', $this->asset['leaf1']->id))->assertRedirect()->assertSessionHas('success');
        $this->actingAsForApi($user)->postJson(route('api.assets.restore', $this->asset['leaf2']->id))->assertOk()->assertStatusMessageIs('success');

        $bulk = [$this->asset['direct']->id, $this->asset['loose']->id];
        $this->actingAs($user, 'web')->post(route('hardware.bulkedit.show'), ['ids' => $bulk, 'bulk_actions' => 'restore'])->assertOk()->assertViewIs('hardware.bulk-restore');
        $this->actingAs($user, 'web')->post(route('hardware/bulkrestore'), ['ids' => $bulk])->assertSessionHas('success');

        foreach (['leaf1', 'leaf2', 'direct', 'loose'] as $key) {
            $this->assertFalse($this->isDeleted($this->asset[$key]), $key);
        }
    }

    public static function restoreDenials(): array
    {
        return [
            'missing global Delete' => [['view', 'delete'], ['assets.view' => '1', 'assets.create' => '1', 'assets.edit' => '1']],
            'missing category Delete' => [['view', 'create', 'update'], self::ALL_ASSET_PERMISSIONS],
            'missing category Delete, admin' => [['view', 'create', 'update'], self::ALL_ASSET_PERMISSIONS + ['admin' => '1']],
        ];
    }

    #[Test]
    #[DataProvider('restoreDenials')]
    public function users_missing_global_or_category_delete_are_offered_no_restore_and_cannot_restore(array $operations, array $permissions): void
    {
        foreach (['leaf1', 'leaf2'] as $key) {
            $this->asset[$key]->delete();
        }
        $user = $this->writer([['leaf1' => $operations, 'leaf2' => $operations]], $permissions);
        $ids = [$this->asset['leaf1']->id, $this->asset['leaf2']->id];

        $actions = $this->deletedRowActions($user, $this->asset['leaf1']);
        $this->assertFalse($actions['restore']);
        $this->assertFalse($actions['bulk_selectable']['restore']);
        $this->actingAs($user, 'web');
        $this->assertFalse(Gate::allows('restoreRecord', Asset::withTrashed()->find($this->asset['leaf1']->id)));
        $this->assertStringNotContainsString(
            route('restore/hardware', ['asset' => $this->asset['leaf1']->id]),
            $this->actingAs($user, 'web')->get(route('hardware.show', $this->asset['leaf1']))->assertOk()->getContent()
        );

        $this->actingAs($user, 'web')->post(route('restore/hardware', $ids[0]))->assertForbidden();
        $this->actingAsForApi($user)->postJson(route('api.assets.restore', $ids[0]))->assertForbidden();
        $this->actingAs($user, 'web')->post(route('hardware.bulkedit.show'), ['ids' => $ids, 'bulk_actions' => 'restore'])->assertForbidden();
        $this->actingAs($user, 'web')->post(route('hardware/bulkrestore'), ['ids' => $ids])->assertForbidden();

        $this->assertTrue($this->isDeleted($this->asset['leaf1']));
        $this->assertTrue($this->isDeleted($this->asset['leaf2']));
    }

    // ---------------------------------------------------------------
    // Web bulk selection normalisation
    // ---------------------------------------------------------------

    public static function malformedSelections(): array
    {
        return [
            'nested array' => [[['1', '2']]],
            'deeply nested' => [[[['1']]]],
            'valid id next to a nested array' => [['__valid__', ['2']]],
            'zero' => [['0']],
            'integer zero' => [[0]],
            'negative' => [['-1']],
            'negative integer' => [[-5]],
            'decimal string' => [['1.5']],
            'decimal float' => [[1.5]],
            'boolean true' => [[true]],
            'boolean false' => [[false]],
            'valid id next to a boolean' => [['__valid__', true]],
            'letters' => [['abc']],
            'id with a suffix' => [['1abc']],
            'exponent' => [['1e3']],
            'signed' => [['+1']],
            'hex' => [['0x1']],
            'too long' => [['1234567890123456789']],
            'scalar string' => ['1,2'],
            'scalar malformed' => ['not-an-array'],
        ];
    }

    /** Replace the __valid__ placeholder with a real authorised asset id. */
    private function resolve(mixed $ids, int $validId): mixed
    {
        if (! is_array($ids)) {
            return $ids;
        }

        return array_map(fn ($id) => $id === '__valid__' ? $validId : $id, $ids);
    }

    #[Test]
    #[DataProvider('malformedSelections')]
    public function every_web_bulk_write_path_refuses_malformed_selections_generically(mixed $ids): void
    {
        $user = $this->writer([['leaf1' => ['view', 'create', 'update', 'delete'], 'leaf2' => ['view', 'create', 'update', 'delete']]]);
        $this->asset['leaf2']->delete();
        $ids = $this->resolve($ids, $this->asset['leaf1']->id);
        $generic = trans('admin/hardware/message.bulk_selection_unavailable');
        $before = $this->snapshot();

        $requests = [
            'bulk edit form' => [route('hardware.bulkedit.show'), ['ids' => $ids, 'bulk_actions' => 'edit']],
            'bulk delete confirmation' => [route('hardware.bulkedit.show'), ['ids' => $ids, 'bulk_actions' => 'delete']],
            'bulk restore confirmation' => [route('hardware.bulkedit.show'), ['ids' => $ids, 'bulk_actions' => 'restore']],
            'bulk update' => [route('hardware/bulksave'), ['ids' => $ids, 'notes' => 'malformed', 'name' => 'malformed']],
            'bulk delete' => [route('hardware.bulkdelete.store'), ['ids' => $ids]],
            'bulk restore' => [route('hardware/bulkrestore'), ['ids' => $ids]],
        ];

        foreach ($requests as $path => [$url, $data]) {
            $response = $this->actingAs($user, 'web')->post($url, $data);
            $this->assertSame(302, $response->status(), $path);
            $this->assertSame($generic, session('error'), $path);
            session()->forget('error');
        }

        $this->assertSame($before, $this->snapshot(), 'Nothing may change.');
    }

    #[Test]
    public function duplicate_ids_are_deduplicated_and_still_work(): void
    {
        $user = $this->writer([['leaf1' => ['view', 'update', 'delete'], 'leaf2' => ['view', 'update', 'delete']]]);
        $id1 = $this->asset['leaf1']->id;
        $id2 = $this->asset['leaf2']->id;

        $this->assertSame([$id1, $id2], AssetCategoryWriteAuthorizer::normalizeSelection([(string) $id1, $id1, $id2, (string) $id2]));

        $this->actingAs($user, 'web')->post(route('hardware/bulksave'), ['ids' => [$id1, (string) $id1, $id2], 'notes' => 'deduplicated'])->assertSessionHas('success');
        $this->assertSame('deduplicated', $this->rawAsset($this->asset['leaf1'])->notes);
        $this->assertSame('deduplicated', $this->rawAsset($this->asset['leaf2'])->notes);

        $this->actingAs($user, 'web')->post(route('hardware.bulkdelete.store'), ['ids' => [$id1, (string) $id1, $id2]])->assertSessionHas('success');
        $this->assertTrue($this->isDeleted($this->asset['leaf1']));

        $this->actingAs($user, 'web')->post(route('hardware/bulkrestore'), ['ids' => [(string) $id2, $id2, $id1]])->assertSessionHas('success');
        $this->assertFalse($this->isDeleted($this->asset['leaf1']));
        $this->assertFalse($this->isDeleted($this->asset['leaf2']));
    }

    #[Test]
    public function the_normaliser_rejects_everything_but_positive_integer_ids(): void
    {
        foreach ([null, '', [], 'x', 5, [null], [[]], [0], ['0'], [-1], ['-1'], [1.0], ['1.0'], [true], [false], ['01x'], [' 1'], ['1 '], [PHP_INT_MAX + 1]] as $input) {
            $this->assertNull(AssetCategoryWriteAuthorizer::normalizeSelection($input), json_encode($input));
        }

        $this->assertSame([3, 1], AssetCategoryWriteAuthorizer::normalizeSelection(['3', 1, '01', 3]));
    }

    // ---------------------------------------------------------------
    // Clone
    // ---------------------------------------------------------------

    #[Test]
    public function clone_is_offered_exactly_when_the_clone_page_accepts_it(): void
    {
        $cases = [
            'creator' => [$this->writer([['leaf1' => ['view', 'create']]]), true],
            'no category Create' => [$this->writer([['leaf1' => ['view', 'update', 'delete']]]), false],
            'no global Create' => [$this->writer([['leaf1' => ['view', 'create']]], ['assets.view' => '1', 'assets.edit' => '1']), false],
            'super admin' => [$this->superUser(), true],
        ];
        $asset = $this->asset['leaf1'];

        foreach ($cases as $case => [$user, $expected]) {
            $row = collect($this->actingAsForApi($user)->getJson(route('api.assets.index', ['limit' => 500]))->assertOk()->json('rows'))->firstWhere('id', $asset->id);
            $this->assertSame($expected, $row['available_actions']['clone'], $case);

            $page = $this->actingAs($user, 'web')->get(route('hardware.show', $asset))->assertOk()->getContent();
            $this->assertSame($expected, str_contains($page, route('clone/hardware', $asset->id).'"'), $case);

            $this->assertSame($expected ? 200 : 403, $this->actingAs($user, 'web')->get(route('clone/hardware', $asset->id))->status(), $case);
        }
    }

    public static function brokenCloneSources(): array
    {
        return [
            'model soft-deleted' => ['soft-deleted'],
            'model row missing' => ['missing'],
            'model moved to a navigation category' => ['navigation'],
            'model moved to a deleted category' => ['deleted-category'],
        ];
    }

    #[Test]
    #[DataProvider('brokenCloneSources')]
    public function an_asset_without_a_valid_live_model_offers_no_clone_even_to_super_admin(string $breakage): void
    {
        $asset = $this->assetIn($this->cat['leaf1'], ['name' => 'Broken source']);
        match ($breakage) {
            'soft-deleted' => AssetModel::find($asset->model_id)->delete(),
            'missing' => DB::table('assets')->where('id', $asset->id)->update(['model_id' => 987654]),
            'navigation' => DB::table('models')->where('id', $asset->model_id)->update(['category_id' => $this->cat['groupA']->id]),
            'deleted-category' => DB::table('models')->where('id', $asset->model_id)->update(['category_id' => $this->cat['deleted']->id]),
        };
        $admin = $this->superUser();
        $before = $this->assetCount();

        $this->assertFalse(app(AssetCategoryWriteAuthorizer::class)->canClone(Asset::find($asset->id), $admin));

        $row = collect($this->actingAsForApi($admin)->getJson(route('api.assets.index', ['limit' => 500]))->assertOk()->json('rows'))->firstWhere('id', $asset->id);
        $this->assertNotNull($row, 'Super Admin still sees the asset.');
        $this->assertFalse($row['available_actions']['clone']);

        $page = $this->actingAs($admin, 'web')->get(route('hardware.show', $asset->id))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('clone/hardware', $asset->id).'"', $page);

        $this->actingAs($admin, 'web')->get(route('clone/hardware', $asset->id))->assertForbidden();
        $this->assertSame($before, $this->assetCount());

        // A category-restricted creator with every grant gets the same answer.
        $creator = $this->writer([['leaf1' => ['view', 'create'], 'groupA' => ['view', 'create'], 'deleted' => ['view', 'create']]]);
        $this->assertFalse(app(AssetCategoryWriteAuthorizer::class)->canClone(Asset::withoutGlobalScopes()->find($asset->id), $creator));
    }
}
