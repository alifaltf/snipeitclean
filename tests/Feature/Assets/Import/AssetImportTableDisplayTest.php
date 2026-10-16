<?php

namespace Tests\Feature\Assets\Import;

use App\Actions\AssetImport\ExecuteAssetImport;
use App\Models\AssetImportSession;
use App\Models\User;
use App\Services\AssetTableLayout\AssetTableFieldCatalog;
use App\Services\AssetTableLayout\AssetTableLayoutService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 7C end to end: a final category, a model with a fieldset,
 * standard and custom fields, a category table layout, a secure Phase 6
 * import through the HTTP workflow, then the category table API and the
 * category CSV export show the imported values in the layout's order,
 * with encrypted values masked unless the viewer may see them and
 * spreadsheet formulas escaped in the export.
 */
class AssetImportTableDisplayTest extends TestCase
{
    use BuildsAssetImportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportFixture();
    }

    #[Test]
    public function imported_values_appear_in_the_category_table_and_export(): void
    {
        $model = $this->modelIn('leaf1');
        $colour = $this->customFieldOn($model, ['name' => $this->randomName('Colour')]);
        $vault = $this->customFieldOn($model->fresh(), ['name' => $this->randomName('Vault'), 'field_encrypted' => 1]);
        $category = $this->cat['leaf1'];

        // Layout: encrypted field first, then a standard field, then the plain field.
        $this->actingAs(User::factory()->superuser()->create(), 'web');
        app(AssetTableLayoutService::class)->save($category->fresh(), [
            'standard:asset_tag', AssetTableFieldCatalog::customFieldKey($vault->id), 'standard:serial', AssetTableFieldCatalog::customFieldKey($colour->id),
        ]);

        // Secure import (upload, target, mapping, validation, confirmed execution).
        $importer = $this->importer(extra: ['assets.view.encrypted_custom_fields' => '1']);
        $session = $this->configuredSession($importer, "Asset Tag,Serial Number,Colour,Vault\nE2E-1,SER-1,=SUM(1;2),vault-secret-1\nE2E-2,SER-2,teal,vault-secret-2\n", [], [
            0 => 'standard:asset_tag', 1 => 'standard:serial', 2 => 'custom_field:'.$colour->id, 3 => 'custom_field:'.$vault->id,
        ]);
        $this->postValidate($importer, $session)->assertRedirect(route('hardware.import.results', $session->public_id));
        $this->actingAs($importer, 'web')->post(route('hardware.import.execute', $session->public_id), [
            'confirm' => '1', 'confirmation' => ExecuteAssetImport::confirmationToken($session->fresh()),
        ])->assertRedirect(route('hardware.import.index'));
        $this->assertSame(AssetImportSession::STATE_COMPLETED, $session->fresh()->state);

        // Saved.
        $row = DB::table('assets')->where('asset_tag', 'E2E-1')->first();
        $this->assertSame(['SER-1', '=SUM(1;2)', 'vault-secret-1'], [$row->serial, $row->{$colour->db_column}, Crypt::decrypt($row->{$vault->db_column})]);

        $expectedFields = ['asset_tag', $vault->db_column, 'serial', $colour->db_column];
        $masked = strtoupper(trans('admin/custom_fields/general.encrypted'));

        foreach ([[[], $masked], [['assets.view.encrypted_custom_fields' => '1'], 'vault-secret-1']] as [$extra, $expectedVault]) {
            $viewer = $this->viewer([['leaf1']], ['assets.view' => '1'] + $extra);

            // Category table API: the layout's columns, in order, with the values.
            $json = $this->actingAsForApi($viewer)->getJson(route('api.assets.index', ['asset_category' => $category->id, 'asset_table_layout' => '1', 'sort' => 'asset_tag', 'order' => 'asc']))->assertOk();
            $this->assertSame($expectedFields, array_column($json->json('layout.columns'), 'field'));
            $rows = collect($json->json('rows'))->keyBy('asset_tag');
            $this->assertSame('SER-1', $rows['E2E-1']['serial']);
            $this->assertSame('=SUM(1;2)', $rows['E2E-1']['custom_fields'][$colour->name]['value']);
            $this->assertSame($expectedVault, $rows['E2E-1']['custom_fields'][$vault->name]['value']);
            if ($extra === []) {
                $this->assertStringNotContainsString('vault-secret', $json->getContent());
            }

            // Category export: same columns and allowed values; formulas escaped.
            $body = $this->actingAs($viewer, 'web')->get(route('hardware.category-export', ['asset_category' => $category->id, 'sort' => 'asset_tag', 'order' => 'asc']))->assertOk()->streamedContent();
            $handle = fopen('php://memory', 'r+');
            fwrite($handle, substr($body, 3));
            rewind($handle);
            $csv = [];
            while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $csv[] = $line;
            }
            fclose($handle);
            $this->assertSame([trans('admin/hardware/table.asset_tag'), $vault->name, trans('admin/hardware/form.serial'), $colour->name], $csv[0]);
            $byTag = collect(array_slice($csv, 1))->keyBy(0);
            $this->assertSame(['E2E-1', $expectedVault, 'SER-1', '`=SUM(1;2)'], $byTag['E2E-1']);
            $this->assertSame('teal', $byTag['E2E-2'][3]);
            if ($extra === []) {
                $this->assertStringNotContainsString('vault-secret', $body);
            }
        }
    }
}
