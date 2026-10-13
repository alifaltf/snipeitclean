<?php

namespace Tests\Feature\Assets\CategoryTable;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Statuslabel;
use App\Models\User;
use App\Services\AssetTableLayout\AssetTableFieldCatalog;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CategoryAssetTableApiTest extends TestCase
{
    use BuildsCategoryTableFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCategoryTable();
    }

    private function api(User $user, array $query)
    {
        return $this->actingAsForApi($user)->getJson(route('api.assets.index', $query))->assertOk();
    }

    private function tags($response): array
    {
        return array_map(fn ($row) => html_entity_decode($row['asset_tag']), $response->json('rows'));
    }

    // ------------------------------------------------------------------
    // Layout metadata and compatibility
    // ------------------------------------------------------------------

    #[Test]
    public function the_api_describes_the_explicit_layout_in_its_saved_order(): void
    {
        $this->layout(['standard:serial', $this->key($this->plainField), 'standard:asset_tag', $this->key($this->encryptedField), 'standard:assigned_to']);
        $this->asset(['asset_tag' => 'L-1']);

        $layout = $this->api(User::factory()->superuser()->create(), $this->layoutQuery())->json('layout');

        $this->assertSame($this->category->id, $layout['category_id']);
        $this->assertTrue($layout['explicit']);
        $this->assertSame(['serial', $this->plainField->db_column, 'asset_tag', $this->encryptedField->db_column, 'assigned_to'], array_column($layout['columns'], 'field'));
        $this->assertSame([trans('admin/hardware/form.serial'), $this->plainField->name, trans('admin/hardware/table.asset_tag'), $this->encryptedField->name, trans('admin/hardware/form.checkedout_to')], array_column($layout['columns'], 'title'));
        $this->assertSame([true, true, true, false, true], array_column($layout['columns'], 'sortable'), 'Encrypted fields are never sortable.');
        $this->assertSame([true, true, true, false, false], array_column($layout['columns'], 'searchable'));
        $this->assertSame([false, false, false, true, false], array_column($layout['columns'], 'encrypted'));
    }

    #[Test]
    public function an_unconfigured_category_uses_the_default_layout(): void
    {
        $layout = $this->api(User::factory()->superuser()->create(), $this->layoutQuery())->json('layout');

        $this->assertFalse($layout['explicit']);
        $this->assertSame(array_map(fn ($key) => substr($key, strlen('standard:')), AssetTableFieldCatalog::DEFAULT_KEYS), array_column($layout['columns'], 'field'));
    }

    #[Test]
    public function without_the_layout_parameter_or_with_a_navigation_group_the_response_is_unchanged(): void
    {
        $this->layout(['standard:serial']);
        $this->asset(['asset_tag' => 'U-1', 'serial' => 'SER-U1']);
        $super = User::factory()->superuser()->create();

        foreach ([
            ['asset_category' => $this->category->id],
            ['asset_category' => $this->group->id, 'asset_table_layout' => '1'],
            ['asset_table_layout' => '1'],
            ['asset_category' => $this->category->id, 'asset_table_layout' => 'yes'],
            ['asset_category' => [$this->category->id], 'asset_table_layout' => '1'],
        ] as $query) {
            $response = $this->api($super, $query);
            $this->assertArrayNotHasKey('layout', $response->json(), json_encode($query));
            $this->assertArrayHasKey('serial', $response->json('rows.0'));
        }

        // The plain list still searches every field as before (here: serial).
        $this->assertSame(['U-1'], $this->tags($this->api($super, ['asset_category' => $this->category->id, 'search' => 'SER-U1'])));
    }

    // ------------------------------------------------------------------
    // Permissions and scope
    // ------------------------------------------------------------------

    #[Test]
    public function category_view_permissions_still_decide_which_assets_are_returned(): void
    {
        $this->layout(['standard:asset_tag']);
        $other = Category::factory()->assignableAssetCategory()->create(['name' => 'Other '.uniqid()]);
        $otherModel = AssetModel::factory()->create(['category_id' => $other->id]);
        $this->asset(['asset_tag' => 'MINE-1']);
        Asset::factory()->create(['model_id' => $otherModel->id, 'asset_tag' => 'HIDDEN-1']);

        // Granted the fixture category only.
        $viewer = $this->viewer([], $this->category);
        $this->assertSame(['MINE-1'], $this->tags($this->api($viewer, $this->layoutQuery())));

        // Asking for the other category's layout: not offered, and its
        // assets are never returned.
        $response = $this->api($viewer, ['asset_category' => $other->id, 'asset_table_layout' => '1']);
        $this->assertArrayNotHasKey('layout', $response->json());
        $this->assertNotContains('HIDDEN-1', $this->tags($response));

        // No grant at all: nothing.
        $none = $this->viewer([]);
        $this->assertSame([], $this->tags($this->api($none, $this->layoutQuery())));
    }

    #[Test]
    public function company_scope_still_applies(): void
    {
        $this->settings->enableMultipleFullCompanySupport();
        $this->layout(['standard:asset_tag']);
        [$mine, $theirs] = Company::factory()->count(2)->create();
        $this->asset(['asset_tag' => 'CO-MINE', 'company_id' => $mine->id]);
        $this->asset(['asset_tag' => 'CO-THEIRS', 'company_id' => $theirs->id]);
        $viewer = $this->viewer([], $this->category);
        $mine->users()->save($viewer);

        $response = $this->api($viewer->fresh(), $this->layoutQuery());
        $this->assertSame(['CO-MINE'], $this->tags($response));
        $this->assertSame(1, $response->json('total'));
    }

    #[Test]
    public function encrypted_values_need_the_encrypted_field_permission(): void
    {
        $this->layout(['standard:asset_tag', $this->key($this->encryptedField)]);
        $asset = $this->asset(['asset_tag' => 'ENC-1'], null, 'TOP-SECRET-VALUE');
        $ciphertext = DB::table('assets')->where('id', $asset->id)->value($this->encryptedField->db_column);

        $without = $this->api($this->viewer([], $this->category), $this->layoutQuery());
        $this->assertStringNotContainsString('TOP-SECRET-VALUE', $without->getContent());
        $this->assertStringNotContainsString($ciphertext, $without->getContent());
        $this->assertSame(strtoupper(trans('admin/custom_fields/general.encrypted')), $without->json('rows.0.custom_fields')[$this->encryptedField->name]['value']);

        $with = $this->api($this->viewer(['assets.view.encrypted_custom_fields' => '1'], $this->category), $this->layoutQuery());
        $this->assertSame('TOP-SECRET-VALUE', $with->json('rows.0.custom_fields')[$this->encryptedField->name]['value']);
        $this->assertStringNotContainsString($ciphertext, $with->getContent());

        // Searching for the plaintext never matches (encrypted columns are not searchable).
        $this->assertSame([], $this->tags($this->api(User::factory()->superuser()->create(), $this->layoutQuery(['search' => 'TOP-SECRET']))));
    }

    // ------------------------------------------------------------------
    // Search
    // ------------------------------------------------------------------

    #[Test]
    public function search_only_looks_at_the_layouts_searchable_columns(): void
    {
        $this->layout(['standard:asset_tag', 'standard:model', $this->key($this->plainField)]);
        $this->asset(['asset_tag' => 'S-1', 'serial' => 'ZEBRA-SERIAL', 'notes' => 'zebra note'], 'Alpha value');
        $this->asset(['asset_tag' => 'S-2', 'name' => 'zebra name'], 'Beta value');
        $this->asset(['asset_tag' => 'ZEBRA-TAG']);
        $super = User::factory()->superuser()->create();

        $this->assertSame(['ZEBRA-TAG'], $this->tags($this->api($super, $this->layoutQuery(['search' => 'zebra']))), 'serial, notes and name are hidden columns');
        $this->assertSame(['S-2'], $this->tags($this->api($super, $this->layoutQuery(['search' => 'beta VAL']))), 'custom field, case-insensitive');
        $this->assertCount(3, $this->tags($this->api($super, $this->layoutQuery(['search' => $this->model->name]))), 'model name');
        $this->assertSame([], $this->tags($this->api($super, $this->layoutQuery(['search' => '%']))), 'wildcards are literal');
        $this->assertNotContains('ZEBRA-TAG', $this->api($super, $this->layoutQuery(['search' => ['zebra']]))->json('rows') ?? [], 'a non-string search is refused or ignored');

        // Per-column filters: layout columns only; others ignored.
        $this->assertSame(['S-1'], $this->tags($this->api($super, $this->layoutQuery(['filter' => json_encode([$this->plainField->db_column => 'alpha'])]))));
        $this->assertCount(3, $this->tags($this->api($super, $this->layoutQuery(['filter' => json_encode(['serial' => 'ZEBRA'])]))), 'hidden column filter ignored');
        $this->assertCount(3, $this->tags($this->api($super, $this->layoutQuery(['filter' => json_encode(['assets.serial' => 'ZEBRA'])]))));
        $this->assertNull($this->api($super, $this->layoutQuery(['filter' => 'not json']))->json('rows'), 'malformed filters are refused by the existing request validation');
        // A hidden field's exact-match parameter is ignored too.
        $this->assertCount(3, $this->tags($this->api($super, $this->layoutQuery(['serial' => 'nothing-matches']))));
    }

    #[Test]
    public function exact_match_filters_on_hidden_fields_never_affect_results_or_totals(): void
    {
        $this->layout(['standard:asset_tag', 'standard:name']);
        $otherModel = AssetModel::factory()->create(['category_id' => $this->category->id, 'fieldset_id' => $this->model->fieldset_id]);
        $status = Statuslabel::factory()->rtd()->create();
        $pending = Statuslabel::factory()->pending()->create();
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $location = \App\Models\Location::factory()->create();
        $supplier = \App\Models\Supplier::factory()->create();
        $holder = User::factory()->create();
        $this->asset(['asset_tag' => 'H-1', 'serial' => 'SER-1', 'status_id' => $status->id, 'company_id' => $companyA->id, 'location_id' => $location->id,
            'rtd_location_id' => $location->id, 'supplier_id' => $supplier->id, 'order_number' => 'PO-1', 'byod' => 1, 'requestable' => 1,
            'assigned_to' => $holder->id, 'assigned_type' => User::class], 'plain-1');
        $this->asset(['asset_tag' => 'H-2', 'serial' => 'SER-2', 'status_id' => $pending->id, 'company_id' => $companyB->id, 'byod' => 0, 'requestable' => 0], 'plain-2', null, $otherModel);
        $super = User::factory()->superuser()->create();

        $hiddenFilters = [
            ['serial' => 'SER-1'], ['status_id' => (string) $status->id], ['model_id' => (string) $otherModel->id], ['company_id' => (string) $companyA->id],
            ['location_id' => (string) $location->id], ['rtd_location_id' => (string) $location->id], ['supplier_id' => (string) $supplier->id],
            ['manufacturer_id' => '999999'], ['order_number' => 'PO-1'], ['byod' => '1'], ['requestable' => 'true'], ['asset_eol_date' => '2001-01-01'],
            ['assigned_to' => (string) $holder->id, 'assigned_type' => User::class], ['status_type' => 'Pending'], ['status_type' => 'RTD'],
            ['status_type' => 'Archived'], ['status_type' => 'Requestable'], ['status_type' => 'Deployed'], ['status_type' => 'byod'],
            ['category_id' => '999999'], ['depreciation_id' => '999999'], [$this->plainField->db_column => 'plain-1'],
        ];
        foreach ($hiddenFilters as $filter) {
            $response = $this->api($super, $this->layoutQuery($filter));
            $this->assertSame(['H-1', 'H-2'], collect($this->tags($response))->sort()->values()->all(), json_encode($filter));
            $this->assertSame(2, $response->json('total'), json_encode($filter));
        }

        // The same filters apply once their field is a column of the layout.
        $this->layout(['standard:asset_tag', 'standard:serial', 'standard:status', 'standard:model', 'standard:company', 'standard:order_number', 'standard:byod', 'standard:assigned_to', $this->key($this->plainField)]);
        $shown = [
            [['serial' => 'SER-1'], ['H-1']], [['status_id' => (string) $pending->id], ['H-2']], [['model_id' => (string) $otherModel->id], ['H-2']],
            [['company_id' => (string) $companyB->id], ['H-2']], [['order_number' => 'PO-1'], ['H-1']], [['byod' => '1'], ['H-1']],
            [['assigned_to' => (string) $holder->id, 'assigned_type' => User::class], ['H-1']], [['status_type' => 'Pending'], ['H-2']],
            [['status_type' => 'Deployed'], ['H-1']], [[$this->plainField->db_column => 'plain-2'], ['H-2']],
            [['company_id' => ['x']], ['H-1', 'H-2']], [['status_id' => '1 OR 1=1'], ['H-1', 'H-2']],
        ];
        foreach ($shown as [$filter, $expected]) {
            $response = $this->api($super, $this->layoutQuery($filter));
            $this->assertSame($expected, collect($this->tags($response))->sort()->values()->all(), json_encode($filter));
            $this->assertSame(count($expected), $response->json('total'));
        }

        // Normal API behaviour (no layout) is unchanged: hidden fields filter as before.
        $this->assertSame(['H-1'], $this->tags($this->api($super, ['asset_category' => $this->category->id, 'serial' => 'SER-1'])));
    }

    #[Test]
    public function a_custom_value_left_by_another_fieldset_is_not_searchable(): void
    {
        $this->layout(['standard:asset_tag', $this->key($this->plainField)]);
        $bareModel = AssetModel::factory()->create(['category_id' => $this->category->id, 'fieldset_id' => null]);
        $this->asset(['asset_tag' => 'STALE-1'], 'stale-value', null, $bareModel);
        $this->asset(['asset_tag' => 'LIVE-1'], 'stale-value');

        $this->assertSame(['LIVE-1'], $this->tags($this->api(User::factory()->superuser()->create(), $this->layoutQuery(['search' => 'stale-value']))));
    }

    // ------------------------------------------------------------------
    // Sorting, totals and pagination
    // ------------------------------------------------------------------

    #[Test]
    public function sorting_uses_layout_columns_only(): void
    {
        $numeric = CustomField::factory()->create(['name' => 'Number '.uniqid(), 'format' => 'NUMERIC']);
        $this->model->fieldset->fields()->attach($numeric->id, ['order' => 3, 'required' => 0]);
        $this->layout(['standard:asset_tag', 'standard:status', $this->key($this->plainField), $this->key($numeric->fresh())]);
        $numeric = $numeric->fresh();
        $ready = Statuslabel::factory()->rtd()->create(['name' => 'A Ready']);
        $pending = Statuslabel::factory()->pending()->create(['name' => 'B Pending']);
        $a = $this->asset(['asset_tag' => 'B-2', 'serial' => 'Z', 'status_id' => $pending->id, 'created_at' => now()->subDays(3)], 'cherry');
        $b = $this->asset(['asset_tag' => 'A-1', 'serial' => 'Y', 'status_id' => $ready->id, 'created_at' => now()->subDays(2)], 'apple');
        $c = $this->asset(['asset_tag' => 'C-3', 'serial' => 'X', 'status_id' => $ready->id, 'created_at' => now()->subDays(1)], 'banana');
        DB::table('assets')->where('id', $a->id)->update([$numeric->db_column => '10']);
        DB::table('assets')->where('id', $b->id)->update([$numeric->db_column => '9']);
        DB::table('assets')->where('id', $c->id)->update([$numeric->db_column => '100']);
        $super = User::factory()->superuser()->create();
        $sorted = fn (array $q) => $this->tags($this->api($super, $this->layoutQuery($q)));

        $this->assertSame(['A-1', 'B-2', 'C-3'], $sorted(['sort' => 'asset_tag', 'order' => 'asc']));
        $this->assertSame(['C-3', 'B-2', 'A-1'], $sorted(['sort' => 'asset_tag', 'order' => 'desc']));
        $this->assertSame(['A-1', 'C-3', 'B-2'], $sorted(['sort' => 'custom_fields.'.$this->plainField->db_column, 'order' => 'asc']));
        $this->assertSame(['A-1', 'B-2', 'C-3'], $sorted(['sort' => $numeric->db_column, 'order' => 'asc']), 'numeric custom fields sort as numbers');
        $this->assertSame('B-2', $sorted(['sort' => 'status', 'order' => 'desc'])[0]);

        // Hidden, unknown or injected sort fields fall back to the default (newest first).
        foreach (['serial', 'assets.serial', 'id; drop table assets', 'category', ['asset_tag']] as $sort) {
            $this->assertSame(['C-3', 'A-1', 'B-2'], $sorted(['sort' => $sort]), json_encode($sort));
        }
    }

    #[Test]
    public function stale_custom_values_sort_as_no_value_in_both_directions(): void
    {
        $this->layout(['standard:asset_tag', $this->key($this->plainField)]);
        $bare = AssetModel::factory()->create(['category_id' => $this->category->id, 'fieldset_id' => null]);
        $deletedModel = AssetModel::factory()->create(['category_id' => $this->category->id, 'fieldset_id' => $this->model->fieldset_id]);
        $this->asset(['asset_tag' => 'V-M'], 'mmm');
        $this->asset(['asset_tag' => 'V-B'], 'bbb');
        $this->asset(['asset_tag' => 'V-NONE']);
        $this->asset(['asset_tag' => 'STALE-A'], 'aaa', null, $bare);  // would sort first ascending
        $this->asset(['asset_tag' => 'STALE-Z'], 'zzz', null, $bare);  // would sort first descending
        $this->asset(['asset_tag' => 'GONE-A'], 'aab', null, $deletedModel);
        $deletedModel->delete();
        $super = User::factory()->superuser()->create();
        $sorted = fn (string $order) => $this->tags($this->api($super, $this->layoutQuery(['sort' => $this->plainField->db_column, 'order' => $order])));

        $asc = $sorted('asc');
        $desc = $sorted('desc');
        $valued = fn (array $tags) => array_values(array_filter($tags, fn ($tag) => in_array($tag, ['V-B', 'V-M'], true)));
        $this->assertSame(['V-B', 'V-M'], $valued($asc));
        $this->assertSame(['V-M', 'V-B'], $valued($desc));
        // Stale values sort exactly like the asset without a value: never
        // between or around the real values by their leftover text.
        foreach ([$asc, $desc] as $order) {
            $positionsOfNoValue = array_keys(array_filter($order, fn ($tag) => ! in_array($tag, ['V-B', 'V-M'], true)));
            $positionsOfValues = array_keys(array_filter($order, fn ($tag) => in_array($tag, ['V-B', 'V-M'], true)));
            $this->assertTrue(max($positionsOfNoValue) < min($positionsOfValues) || min($positionsOfNoValue) > max($positionsOfValues), json_encode($order));
        }
    }

    #[Test]
    public function totals_and_pagination_follow_the_filtered_list(): void
    {
        $this->layout(['standard:asset_tag']);
        foreach (range(1, 7) as $i) {
            $this->asset(['asset_tag' => sprintf('P-%02d', $i)]);
        }
        $this->asset(['asset_tag' => 'Q-01']);
        $super = User::factory()->superuser()->create();

        $page = $this->api($super, $this->layoutQuery(['search' => 'P-', 'sort' => 'asset_tag', 'order' => 'asc', 'limit' => 3, 'offset' => 3]));
        $this->assertSame(7, $page->json('total'));
        $this->assertSame(['P-04', 'P-05', 'P-06'], $this->tags($page));

        $last = $this->api($super, $this->layoutQuery(['search' => 'P-', 'sort' => 'asset_tag', 'order' => 'asc', 'limit' => 3, 'offset' => 6]));
        $this->assertSame(['P-07'], $this->tags($last));
        $this->assertSame(8, $this->api($super, $this->layoutQuery())->json('total'));
    }

    // ------------------------------------------------------------------
    // Stale layouts and N+1
    // ------------------------------------------------------------------

    #[Test]
    public function stale_or_deleted_fields_drop_out_of_the_layout(): void
    {
        $this->layout(['standard:asset_tag', $this->key($this->plainField), $this->key($this->encryptedField)]);
        $super = User::factory()->superuser()->create();

        $this->model->fieldset->fields()->detach($this->encryptedField->id);
        $this->assertSame(['asset_tag', $this->plainField->db_column], array_column($this->api($super, $this->layoutQuery())->json('layout.columns'), 'field'));

        $this->plainField->delete();
        $this->assertSame(['asset_tag'], array_column($this->api($super, $this->layoutQuery())->json('layout.columns'), 'field'));

        // Sorting or searching by a field that left the layout is ignored.
        $this->api($super, $this->layoutQuery(['sort' => $this->encryptedField->db_column, 'search' => 'x']));
    }

    #[Test]
    public function the_number_of_queries_does_not_grow_with_the_number_of_assets(): void
    {
        $this->layout(['standard:asset_tag', 'standard:model', 'standard:status', 'standard:company', 'standard:location', 'standard:assigned_to', $this->key($this->plainField), $this->key($this->encryptedField)]);
        $super = User::factory()->superuser()->create();
        $count = function () use ($super): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->api($super, $this->layoutQuery(['limit' => 50]));
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        // Every asset with its own model, fieldset, company, location and
        // holder, so nothing is shared between rows by accident.
        $add = function (int $i) {
            $fieldset = \App\Models\CustomFieldset::factory()->create(['name' => 'Set '.uniqid()]);
            $fieldset->fields()->attach($this->plainField->id, ['order' => 1, 'required' => 0]);
            $fieldset->fields()->attach($this->encryptedField->id, ['order' => 2, 'required' => 0]);
            $model = AssetModel::factory()->create(['category_id' => $this->category->id, 'fieldset_id' => $fieldset->id]);
            $this->asset([
                'company_id' => Company::factory()->create()->id,
                'location_id' => \App\Models\Location::factory()->create()->id,
                'assigned_to' => User::factory()->create()->id,
                'assigned_type' => User::class,
            ], 'v'.$i, 's'.$i, $model);
        };
        foreach (range(1, 3) as $i) {
            $add($i);
        }
        $count(); // warm caches (settings, permissions)
        $few = $count();
        foreach (range(4, 15) as $i) {
            $add($i);
        }
        $many = $count();

        $this->assertSame($few, $many, "Queries grew from $few to $many with more assets.");
    }
}
