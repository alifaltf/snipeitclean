<?php

namespace Tests\Feature\Assets\Import;

use App\Models\AssetImportSession;
use App\Services\AssetImport\AssetImportSessions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 6A: sample values on the mapping page. A bounded number of
 * rows is read from the private stored file with the upload's CSV rules,
 * each value is shown under its own column as short, escaped plain text,
 * and nothing is stored.
 */
class AssetImportSamplesTest extends TestCase
{
    use BuildsAssetImportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportFixture();
    }

    private function sessionAtMapping(string $csv): array
    {
        $user = $this->importer();
        $session = $this->newSession($user, $csv);
        $this->postTarget($user, $session)->assertSessionHasNoErrors();

        return [$user, $session];
    }

    /** @return array<int, list<string>> column position => sample values, read from the rendered page */
    private function renderedSamples($user, AssetImportSession $session): array
    {
        $html = (string) $this->actingAs($user, 'web')->get(route('hardware.import.mapping', $session->public_id))->assertOk()->getContent();
        preg_match_all('/<ul class="list-unstyled text-muted asset-import-samples" data-column="(\d+)"[^>]*>(.*?)<\/ul>/s', $html, $lists, PREG_SET_ORDER);

        $samples = [];
        foreach ($lists as [, $column, $items]) {
            preg_match_all('/<li>(.*?)<\/li>/s', $items, $values);
            $samples[(int) $column] = array_map('trim', $values[1]);
        }

        return $samples;
    }

    #[Test]
    public function each_header_shows_values_from_its_own_column_including_quoted_commas_and_multiline_cells(): void
    {
        $csv = "Asset Tag,Description,Serial Number\n"
            ."T-1,\"Dell, Latitude 5420\",S-1\n"
            ."T-2,\"Line one\nline two\",S-2\n"
            ."T-3,,S-3\n";
        [$user, $session] = $this->sessionAtMapping($csv);

        $this->assertSame([
            0 => ['T-1', 'T-2', 'T-3'],
            1 => ['Dell, Latitude 5420', 'Line one line two', '—'],
            2 => ['S-1', 'S-2', 'S-3'],
        ], $this->renderedSamples($user, $session));
    }

    #[Test]
    public function html_is_escaped_and_formula_like_values_are_shown_as_plain_text(): void
    {
        $csv = "Asset Tag,Name\n"
            ."=1+1,<script>alert(1)</script>\n"
            ."+CMD,\"<img src=x onerror=alert(2)>\"\n"
            ."-1+2,@SUM(A1)\n";
        [$user, $session] = $this->sessionAtMapping($csv);

        $html = (string) $this->actingAs($user, 'web')->get(route('hardware.import.mapping', $session->public_id))->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);

        $this->assertSame([
            0 => ['=1+1', '+CMD', '-1+2'],
            1 => ['&lt;script&gt;alert(1)&lt;/script&gt;', '&lt;img src=x onerror=alert(2)&gt;', '@SUM(A1)'],
        ], $this->renderedSamples($user, $session));
    }

    #[Test]
    public function only_the_configured_number_of_rows_is_shown_and_long_values_are_cut(): void
    {
        $long = str_repeat('é', 500);
        $rows = implode("\n", array_map(fn ($i) => "ROW-$i,$long", range(1, 50)));
        [$user, $session] = $this->sessionAtMapping("Asset Tag,Notes\n$rows\n");

        $samples = $this->renderedSamples($user, $session);
        $this->assertSame(['ROW-1', 'ROW-2', 'ROW-3'], $samples[0]);
        $max = (int) config('asset_import.sample_value_length');
        foreach ($samples[1] as $value) {
            $this->assertSame(str_repeat('é', $max).'…', html_entity_decode($value));
        }

        config(['asset_import.sample_rows' => 1]);
        $this->assertSame(['ROW-1'], $this->renderedSamples($user, $session)[0]);
    }

    #[Test]
    public function samples_are_never_stored_and_mapping_stays_by_column_position(): void
    {
        [$user, $session] = $this->sessionAtMapping("Serial Number,Asset Tag\nSECRET-SAMPLE,T-1\n");
        $this->renderedSamples($user, $session);
        $this->postMapping($user, $session, [1 => 'standard:asset_tag', 0 => 'standard:serial'])->assertSessionHasNoErrors();

        $row = (array) DB::table('asset_import_sessions')->where('id', $session->id)->first();
        $this->assertStringNotContainsString('SECRET-SAMPLE', json_encode($row));
        $this->assertSame([0 => 'standard:serial', 1 => 'standard:asset_tag'], $session->fresh()->mappedDestinations());
    }

    #[Test]
    public function no_samples_are_shown_for_a_missing_or_modified_file_or_another_users_or_expired_session(): void
    {
        [$user, $session] = $this->sessionAtMapping("Asset Tag,Serial Number\nSAMPLE-1,SAMPLE-2\n");

        $this->actingAs($this->importer(), 'web')->get(route('hardware.import.mapping', $session->public_id))->assertNotFound()->assertDontSee('SAMPLE-1');

        $this->travel((int) config('asset_import.session_lifetime_hours') + 1)->hours();
        $this->actingAs($user, 'web')->get(route('hardware.import.mapping', $session->public_id))->assertNotFound()->assertDontSee('SAMPLE-1');
        $this->travelBack();

        AssetImportSessions::disk()->put($session->storage_path, "Asset Tag,Serial Number\nCHANGED-1,CHANGED-2\n");
        $this->actingAs($user, 'web')->get(route('hardware.import.mapping', $session->public_id))->assertNotFound()->assertDontSee('CHANGED-1');

        AssetImportSessions::disk()->delete($session->storage_path);
        $this->actingAs($user, 'web')->get(route('hardware.import.mapping', $session->public_id))->assertNotFound();
    }

    #[Test]
    public function sample_rendering_does_not_add_queries_per_row_or_column(): void
    {
        $count = function (string $csv): int {
            [$user, $session] = $this->sessionAtMapping($csv);
            $this->actingAs($user, 'web')->get(route('hardware.import.mapping', $session->public_id))->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user, 'web')->get(route('hardware.import.mapping', $session->public_id))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $small = $count("Asset Tag,Serial Number\nT-1,S-1\n");
        $headers = implode(',', array_map(fn ($i) => "Column $i", range(1, 40)));
        $rows = implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($c) => "v$r-$c", range(1, 40))), range(1, 200)));
        $large = $count("$headers\n$rows\n");

        $this->assertSame($small, $large);
    }
}
