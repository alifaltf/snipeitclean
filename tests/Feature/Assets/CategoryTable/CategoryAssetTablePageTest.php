<?php

namespace Tests\Feature\Assets\CategoryTable;

use App\Models\User;
use App\Presenters\AssetPresenter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CategoryAssetTablePageTest extends TestCase
{
    use BuildsCategoryTableFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCategoryTable();
    }

    /** @return list<array<string, mixed>> the table's column definitions */
    private function columns(string $html): array
    {
        $this->assertSame(1, preg_match('/data-columns="([^"]*)"/', $html, $match), 'The asset table is rendered.');

        return json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
    }

    #[Test]
    public function a_final_category_shows_its_columns_in_the_saved_order(): void
    {
        $this->layout(['standard:serial', $this->key($this->plainField), 'standard:asset_tag', $this->key($this->encryptedField)]);

        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_category' => $this->category->id]))->assertOk()->getContent();

        $columns = $this->columns($html);
        $this->assertSame(['checkbox', 'serial', $this->plainField->db_column, 'asset_tag', $this->encryptedField->db_column, 'checkincheckout', 'actions'], array_column($columns, 'field'));
        $this->assertSame('hardwareLinkFormatter', $columns[3]['formatter'], 'Standard columns keep their existing formatting.');
        $this->assertSame('customFieldsFormatter', $columns[2]['formatter']);
        $this->assertSame('css-padlock', $columns[4]['class']);
        $this->assertFalse($columns[4]['sortable']);
        $this->assertFalse($columns[4]['searchable']);
        $this->assertTrue($columns[1]['visible']);

        $this->assertStringContainsString('asset_table_layout=1', $html);
        $this->assertStringContainsString('data-show-export="false"', $html);
        $this->assertMatchesRegularExpression('/data-cookie-id-table="assetsCategory'.$this->category->id.'_[0-9a-f]{12}ListingTable"/', $html);
        $this->assertStringContainsString('id="asset-category-export"', $html);
        $this->assertStringContainsString(route('hardware.category-export', ['asset_category' => $this->category->id]), $html);
    }

    #[Test]
    public function the_export_link_carries_the_pages_list_filters_and_the_tables_state(): void
    {
        $company = \App\Models\Company::factory()->create();
        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_category' => $this->category->id, 'company_id' => $company->id, 'status_type' => 'Pending', 'status_id' => '5', 'order_number' => 'PO-1', 'evil' => 'x']))
            ->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<a href="([^"]+)" class="btn btn-default" id="asset-category-export"/', $html, $match));
        parse_str((string) parse_url(html_entity_decode($match[1]), PHP_URL_QUERY), $query);
        $this->assertSame(['asset_category' => (string) $this->category->id, 'status_type' => 'Pending', 'order_number' => 'PO-1', 'company_id' => (string) $company->id, 'status_id' => '5'], $query);
        // The table's current search, filters and sorting are added on click.
        $this->assertStringContainsString("data('ers-last-query')", $html);
        $this->assertStringContainsString("['search', 'sort', 'order', 'filter', 'filter_operator']", $html);
    }

    #[Test]
    public function the_default_layout_is_used_until_one_is_saved(): void
    {
        $html = $this->actingAs(User::factory()->superuser()->create())
            ->get(route('hardware.index', ['asset_category' => $this->category->id]))->assertOk()->getContent();

        $this->assertSame(['checkbox', 'asset_tag', 'name', 'model', 'serial', 'status', 'checkincheckout', 'actions'], array_column($this->columns($html), 'field'));
    }

    #[Test]
    public function navigation_groups_and_all_assets_keep_the_normal_columns(): void
    {
        $this->layout(['standard:serial']);
        $super = User::factory()->superuser()->create();
        $normal = array_column(json_decode(AssetPresenter::dataTableLayout(['deleted_at']), true), 'field');

        foreach ([['asset_category' => $this->group->id], [], ['asset_category' => 'abc']] as $query) {
            $html = $this->actingAs($super)->get(route('hardware.index', $query))->assertOk()->getContent();
            $this->assertSame($normal, array_column($this->columns($html), 'field'), json_encode($query));
            $this->assertStringNotContainsString('asset_table_layout', $html);
            $this->assertStringNotContainsString('id="asset-category-export"', $html);
            $this->assertStringNotContainsString('data-show-export="false"', $html);
            $this->assertStringContainsString('data-cookie-id-table="assetsListingTable"', $html);
        }
    }

    #[Test]
    public function a_category_the_user_may_not_view_shows_no_layout(): void
    {
        $this->layout(['standard:serial']);
        $viewer = $this->viewer([]);

        $html = $this->actingAs($viewer)->get(route('hardware.index', ['asset_category' => $this->category->id]))->assertOk()->getContent();

        $this->assertStringNotContainsString('asset_table_layout', $html);
        $this->assertStringNotContainsString($this->category->name, $html);
    }

    #[Test]
    public function the_page_never_contains_custom_field_values(): void
    {
        $this->layout(['standard:asset_tag', $this->key($this->plainField), $this->key($this->encryptedField)]);
        $this->asset(['asset_tag' => 'PAGE-1'], 'PLAIN-CELL-VALUE', 'SECRET-CELL-VALUE');

        $html = $this->actingAs($this->viewer([], $this->category))
            ->get(route('hardware.index', ['asset_category' => $this->category->id]))->assertOk()->getContent();

        $this->assertStringNotContainsString('PLAIN-CELL-VALUE', $html);
        $this->assertStringNotContainsString('SECRET-CELL-VALUE', $html);
        $this->assertStringContainsString(e($this->encryptedField->name), $html, 'Only the column title.');
    }
}
