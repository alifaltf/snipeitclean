<?php

namespace Tests\Feature\Assets\CategoryTable;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Location;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CategoryAssetExportTest extends TestCase
{
    use BuildsCategoryTableFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCategoryTable();
    }

    /** @return list<list<string>> */
    private function csv(User $user, array $query = []): array
    {
        $response = $this->actingAs($user, 'web')->get(route('hardware.category-export', array_merge(['asset_category' => $this->category->id], $query)));
        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="export-', $response->headers->get('Content-Disposition'));
        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        $rows = [];
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, substr($body, 3));
        rewind($handle);
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function body(User $user, array $query = []): string
    {
        return $this->actingAs($user, 'web')->get(route('hardware.category-export', array_merge(['asset_category' => $this->category->id], $query)))->streamedContent();
    }

    #[Test]
    public function the_export_has_the_categorys_columns_in_order_with_formatted_values(): void
    {
        $this->settings->set(['default_currency' => 'USD', 'digit_separator' => '1,234.56']);
        $this->layout(['standard:asset_tag', 'standard:model', 'standard:status', $this->key($this->plainField), 'standard:purchase_cost', 'standard:purchase_date', 'standard:requestable', 'standard:warranty_months', 'standard:location', 'standard:supplier', 'standard:company', 'standard:manufacturer', 'standard:assigned_to', 'standard:notes']);
        $status = Statuslabel::factory()->rtd()->create(['name' => 'Ready Now']);
        $location = Location::factory()->create(['name' => 'Room 1']);
        $supplier = Supplier::factory()->create(['name' => 'Vendor 1']);
        $company = Company::factory()->create(['name' => 'Company 1']);
        $holder = User::factory()->create(['first_name' => 'Holder', 'last_name' => 'Person', 'display_name' => 'Holder Person']);
        $this->asset([
            'asset_tag' => 'EXP-1', 'status_id' => $status->id, 'purchase_cost' => 1234.5, 'purchase_date' => '2024-02-03',
            'requestable' => 1, 'warranty_months' => 12, 'location_id' => $location->id, 'supplier_id' => $supplier->id,
            'company_id' => $company->id, 'assigned_to' => $holder->id, 'assigned_type' => User::class, 'notes' => "line one\nline two",
        ], 'plain value');
        $this->asset(['asset_tag' => 'EXP-2', 'status_id' => $status->id, 'requestable' => 0]);

        $rows = $this->csv(User::factory()->superuser()->create(), ['sort' => 'asset_tag', 'order' => 'asc']);

        $this->assertSame([
            trans('admin/hardware/table.asset_tag'), trans('admin/hardware/form.model'), trans('admin/hardware/table.status'), $this->plainField->name,
            trans('general.purchase_cost'), trans('general.purchase_date'), trans('admin/hardware/general.requestable'), trans('admin/hardware/form.warranty'),
            trans('admin/hardware/table.location'), trans('general.supplier'), trans('general.company'), trans('general.manufacturer'),
            trans('admin/hardware/form.checkedout_to'), trans('general.notes'),
        ], $rows[0]);
        $this->assertCount(3, $rows);
        $first = $rows[1];
        $this->assertSame('EXP-1', $first[0]);
        $this->assertSame($this->model->name, $first[1]);
        $this->assertSame('Ready Now', $first[2]);
        $this->assertSame('plain value', $first[3]);
        $this->assertSame('1,234.50', $first[4]);
        $this->assertStringContainsString('2024', $first[5]);
        $this->assertSame(trans('general.yes'), $first[6]);
        $this->assertSame('12 '.trans('admin/hardware/form.months'), $first[7]);
        $this->assertSame(['Room 1', 'Vendor 1', 'Company 1'], array_slice($first, 8, 3));
        $this->assertSame((string) $this->model->manufacturer?->name, $first[11]);
        $this->assertSame('Holder Person', $first[12]);
        $this->assertSame("line one\nline two", $first[13]);
        $this->assertSame(['EXP-2', trans('general.no'), ''], [$rows[2][0], $rows[2][6], $rows[2][7]], 'Missing values are empty.');
    }

    #[Test]
    public function the_default_layout_is_exported_until_one_is_saved(): void
    {
        $this->asset(['asset_tag' => 'DEF-1']);

        $this->assertSame([trans('admin/hardware/table.asset_tag'), trans('general.name'), trans('admin/hardware/form.model'), trans('admin/hardware/form.serial'), trans('admin/hardware/table.status')],
            $this->csv(User::factory()->superuser()->create())[0]);
    }

    #[Test]
    public function spreadsheet_formulas_are_neutralised_in_values_and_headers(): void
    {
        $formulaField = CustomField::factory()->create(['name' => '=HYPERLINK("http://x")', 'format' => '']);
        $this->model->fieldset->fields()->attach($formulaField->id, ['order' => 3, 'required' => 0]);
        $this->layout(['standard:asset_tag', 'standard:name', $this->key($this->plainField), $this->key($formulaField->fresh())]);
        $this->asset(['asset_tag' => '+1-TAG', 'name' => '=SUM(1,2)'], '@cmd');
        config(['app.escape_formulas' => false]); // the category export escapes regardless

        $rows = $this->csv(User::factory()->superuser()->create());

        $this->assertSame('`=HYPERLINK("http://x")', $rows[0][3]);
        $this->assertSame(['`+1-TAG', '`=SUM(1,2)', '`@cmd'], array_slice($rows[1], 0, 3));
    }

    #[Test]
    public function encrypted_values_are_exported_only_with_the_permission(): void
    {
        $this->layout(['standard:asset_tag', $this->key($this->encryptedField)]);
        $asset = $this->asset(['asset_tag' => 'ENC-1'], null, 'TOP-SECRET-VALUE');
        $ciphertext = DB::table('assets')->where('id', $asset->id)->value($this->encryptedField->db_column);

        $without = $this->body($this->viewer([], $this->category));
        $this->assertStringNotContainsString('TOP-SECRET-VALUE', $without);
        $this->assertStringNotContainsString($ciphertext, $without);
        $this->assertStringContainsString(strtoupper(trans('admin/custom_fields/general.encrypted')), $without);

        $with = $this->body($this->viewer(['assets.view.encrypted_custom_fields' => '1'], $this->category));
        $this->assertStringContainsString('TOP-SECRET-VALUE', $with);
        $this->assertStringNotContainsString($ciphertext, $with);
    }

    #[Test]
    public function only_assets_the_user_may_view_are_exported(): void
    {
        $this->settings->enableMultipleFullCompanySupport();
        $this->layout(['standard:asset_tag']);
        [$mine, $theirs] = Company::factory()->count(2)->create();
        $this->asset(['asset_tag' => 'CO-MINE', 'company_id' => $mine->id]);
        $this->asset(['asset_tag' => 'CO-THEIRS', 'company_id' => $theirs->id]);
        $deleted = $this->asset(['asset_tag' => 'CO-DELETED', 'company_id' => $mine->id]);
        $deleted->delete();
        $archived = Statuslabel::factory()->archived()->create();
        $this->asset(['asset_tag' => 'CO-ARCHIVED', 'company_id' => $mine->id, 'status_id' => $archived->id]);
        $viewer = $this->viewer([], $this->category);
        $mine->users()->save($viewer);

        $this->assertSame([['CO-MINE']], array_slice($this->csv($viewer->fresh()), 1));
    }

    #[Test]
    public function unauthorised_categories_navigation_groups_and_invalid_requests_are_refused(): void
    {
        $other = Category::factory()->assignableAssetCategory()->create(['name' => 'Other '.uniqid()]);
        Asset::factory()->create(['model_id' => AssetModel::factory()->create(['category_id' => $other->id])->id, 'asset_tag' => 'HIDDEN-1']);
        $viewer = $this->viewer([], $this->category);

        foreach ([['asset_category' => $other->id], ['asset_category' => $this->group->id], ['asset_category' => 'abc'], ['asset_category' => [$this->category->id]], []] as $query) {
            $this->actingAs($viewer)->get(route('hardware.category-export', $query))->assertNotFound();
        }

        // Without the global asset view permission.
        $this->actingAs(User::factory()->create())->get(route('hardware.category-export', ['asset_category' => $this->category->id]))->assertForbidden();
        $this->get(route('hardware.category-export', ['asset_category' => $this->category->id]))->assertForbidden();
    }

    #[Test]
    public function search_and_sort_parameters_go_through_the_layout_allowlist(): void
    {
        $this->layout(['standard:asset_tag']);
        $this->asset(['asset_tag' => 'B-1', 'serial' => 'FINDME']);
        $this->asset(['asset_tag' => 'A-1']);
        $super = User::factory()->superuser()->create();

        $this->assertSame([['A-1'], ['B-1']], array_slice($this->csv($super, ['sort' => 'asset_tag', 'order' => 'asc']), 1));
        $this->assertSame([['B-1']], array_slice($this->csv($super, ['search' => 'B-']), 1));
        $this->assertSame([], array_slice($this->csv($super, ['search' => 'FINDME']), 1), 'serial is not a column here');
        $this->assertCount(3, $this->csv($super, ['sort' => 'serial; drop table assets']));
    }

    #[Test]
    public function the_export_contains_exactly_the_tables_filtered_and_sorted_list(): void
    {
        $this->layout(['standard:asset_tag', 'standard:status', 'standard:company', 'standard:order_number', $this->key($this->plainField)]);
        $ready = Statuslabel::factory()->rtd()->create(['name' => 'Ready '.uniqid()]);
        $pending = Statuslabel::factory()->pending()->create(['name' => 'Waiting '.uniqid()]);
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $this->asset(['asset_tag' => 'F-1', 'status_id' => $ready->id, 'company_id' => $companyA->id, 'order_number' => 'PO-7', 'created_at' => now()->subDays(4)], 'red apple');
        $this->asset(['asset_tag' => 'F-2', 'status_id' => $pending->id, 'company_id' => $companyA->id, 'created_at' => now()->subDays(3)], 'green apple');
        $this->asset(['asset_tag' => 'F-3', 'status_id' => $ready->id, 'company_id' => $companyB->id, 'created_at' => now()->subDays(2)], 'red cherry');
        $this->asset(['asset_tag' => 'F-4', 'status_id' => $pending->id, 'company_id' => $companyB->id, 'order_number' => 'PO-7', 'created_at' => now()->subDays(1)], 'plum');
        $super = User::factory()->superuser()->create();
        $plain = $this->plainField->db_column;

        $cases = [
            'company filter' => [['company_id' => (string) $companyB->id], ['F-4', 'F-3']],
            'status filter' => [['status_id' => (string) $pending->id], ['F-4', 'F-2']],
            'status type' => [['status_type' => 'Pending'], ['F-4', 'F-2']],
            'order number' => [['order_number' => 'PO-7'], ['F-4', 'F-1']],
            'search' => [['search' => 'apple'], ['F-2', 'F-1']],
            'column filter' => [['filter' => json_encode([$plain => 'red'])], ['F-3', 'F-1']],
            'column filters, and' => [['filter' => json_encode([$plain => 'red', 'status' => $ready->name]), 'filter_operator' => 'and'], ['F-3', 'F-1']],
            'column filters, or' => [['filter' => json_encode([$plain => 'plum', 'asset_tag' => 'F-1']), 'filter_operator' => 'or'], ['F-4', 'F-1']],
            'sorting' => [['sort' => $plain, 'order' => 'asc'], ['F-2', 'F-4', 'F-1', 'F-3']],
            'combined' => [['company_id' => (string) $companyA->id, 'search' => 'apple', 'sort' => 'asset_tag', 'order' => 'desc'], ['F-2', 'F-1']],
            'forged values ignored' => [['company_id' => ['1'], 'status_id' => '0', 'sort' => 'serial; --', 'filter' => json_encode(['serial' => 'x'])], ['F-4', 'F-3', 'F-2', 'F-1']],
        ];
        foreach ($cases as $label => [$query, $expected]) {
            $table = array_map(fn ($row) => html_entity_decode($row['asset_tag']), $this->actingAsForApi($super)
                ->getJson(route('api.assets.index', array_merge(['asset_category' => $this->category->id, 'asset_table_layout' => '1'], $query)))->json('rows'));
            $export = array_column(array_slice($this->csv($super, $query), 1), 0);
            $this->assertSame($expected, $table, "table: $label");
            $this->assertSame($expected, $export, "export: $label");
        }
    }

    #[Test]
    public function hidden_fields_do_not_filter_the_export(): void
    {
        $this->layout(['standard:asset_tag']);
        $ready = Statuslabel::factory()->rtd()->create();
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $this->asset(['asset_tag' => 'X-1', 'serial' => 'SER-X1', 'status_id' => $ready->id, 'company_id' => $companyA->id]);
        $this->asset(['asset_tag' => 'X-2', 'company_id' => $companyB->id]);
        $super = User::factory()->superuser()->create();

        foreach ([['company_id' => (string) $companyA->id], ['status_id' => (string) $ready->id], ['serial' => 'SER-X1'], ['status_type' => 'RTD'], ['filter' => json_encode(['serial' => 'SER-X1'])]] as $query) {
            $this->assertCount(2, array_slice($this->csv($super, $query), 1), json_encode($query));
        }
    }

    #[Test]
    public function stale_custom_values_and_removed_fields_are_not_exported(): void
    {
        $this->layout(['standard:asset_tag', $this->key($this->plainField)]);
        $bare = AssetModel::factory()->create(['category_id' => $this->category->id, 'fieldset_id' => null]);
        $this->asset(['asset_tag' => 'STALE-1'], 'left-over', null, $bare);
        $super = User::factory()->superuser()->create();

        $this->assertSame(['STALE-1', ''], $this->csv($super)[1]);

        $this->plainField->delete();
        $this->assertSame([trans('admin/hardware/table.asset_tag')], $this->csv($super)[0]);
    }

    #[Test]
    public function exporting_changes_nothing(): void
    {
        $this->layout(['standard:asset_tag', $this->key($this->plainField), $this->key($this->encryptedField)]);
        $this->asset(['asset_tag' => 'RO-1'], 'kept', 'secret');
        $before = DB::table('assets')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $this->body(User::factory()->superuser()->create());

        $this->assertSame($before, DB::table('assets')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    #[Test]
    public function the_number_of_queries_does_not_grow_with_the_number_of_assets(): void
    {
        $this->layout(['standard:asset_tag', 'standard:model', 'standard:status', 'standard:company', 'standard:location', 'standard:manufacturer', 'standard:assigned_to', $this->key($this->plainField), $this->key($this->encryptedField)]);
        $super = User::factory()->superuser()->create();
        $add = function (int $i) {
            $fieldset = \App\Models\CustomFieldset::factory()->create(['name' => 'Set '.uniqid()]);
            $fieldset->fields()->attach($this->plainField->id, ['order' => 1, 'required' => 0]);
            $fieldset->fields()->attach($this->encryptedField->id, ['order' => 2, 'required' => 0]);
            $model = AssetModel::factory()->create(['category_id' => $this->category->id, 'fieldset_id' => $fieldset->id]);
            $this->asset(['company_id' => Company::factory()->create()->id, 'location_id' => Location::factory()->create()->id,
                'assigned_to' => User::factory()->create()->id, 'assigned_type' => User::class], 'v'.$i, 's'.$i, $model);
        };
        $count = function () use ($super): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->body($super);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        foreach (range(1, 3) as $i) {
            $add($i);
        }
        $count();
        $few = $count();
        foreach (range(4, 15) as $i) {
            $add($i);
        }

        $this->assertSame($few, $count());
    }

    #[Test]
    public function the_new_code_names_no_category(): void
    {
        foreach ([
            'app/Services/AssetTableLayout/AssetTableLayoutQuery.php',
            'app/Services/AssetTableLayout/AssetTableValueFormatter.php',
            'app/Http/Controllers/Assets/CategoryAssetExportController.php',
            'resources/views/blade/table/assets.blade.php',
            'resources/views/hardware/index.blade.php',
        ] as $file) {
            $source = file_get_contents(base_path($file));
            foreach (['Laptop', 'Desktop', 'Monitor', 'Phone', 'Chair', 'Hardware', 'Software', 'Fixed', 'Furniture', 'Equipment', 'Drone'] as $name) {
                $this->assertDoesNotMatchRegularExpression('/\b'.$name.'\b/', $source, "$file mentions $name");
            }
            $this->assertDoesNotMatchRegularExpression('/(category_id|asset_category)[\'"]?\s*(=>|==|=)\s*[0-9]/', $source, "$file hardcodes a category id");
        }
    }
}
