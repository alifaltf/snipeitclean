<?php

namespace Tests\Feature\Assets\Import;

use App\Models\AssetImportSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 6B: the validation results/preview page and the downloadable
 * error report. Counts, bounded pagination, escaping, invalid-row marking,
 * no working import action, and a formula-safe CSV that only exists when
 * there are errors.
 */
class AssetImportResultsPageTest extends TestCase
{
    use BuildsAssetImportFixture;

    private const MAPPING = [0 => 'standard:asset_tag', 1 => 'standard:name', 2 => 'standard:purchase_cost'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportFixture();
    }

    private function validated(User $user, string $csv): AssetImportSession
    {
        $session = $this->configuredSession($user, $csv, [], self::MAPPING);
        $this->postValidate($user, $session)->assertRedirect(route('hardware.import.results', $session->public_id));

        return $session->fresh();
    }

    private function page(User $user, AssetImportSession $session, array $query = []): string
    {
        return (string) $this->actingAs($user, 'web')->get(route('hardware.import.results', array_merge([$session->public_id], $query)))->assertOk()->getContent();
    }

    private function countIn(string $html, string $name): string
    {
        preg_match('/data-count="'.$name.'">([^<]*)</', $html, $m);

        return $m[1] ?? '';
    }

    #[Test]
    public function a_valid_session_shows_counts_and_resolved_values_but_no_working_import(): void
    {
        $user = $this->importer();
        $session = $this->validated($user, "Asset Tag,Name,Cost\nT-1,Laptop One,100.50\nT-2,Laptop Two,\n");

        $html = $this->page($user, $session);

        $this->assertSame(['2', '2', '0', '0'], [$this->countIn($html, 'total'), $this->countIn($html, 'valid'), $this->countIn($html, 'invalid'), $this->countIn($html, 'errors')]);
        $this->assertStringContainsString('id="asset-import-eligible"', $html);
        $this->assertStringContainsString('Laptop One', $html);
        $this->assertStringContainsString('100.50', $html);
        $this->assertStringContainsString(e($this->modelIn('leaf1')->name), $html, 'Fixed model is shown.');
        $this->assertStringContainsString(e($this->status->name), $html, 'Fixed status is shown.');
        $this->assertStringContainsString(e(trans('admin/hardware/import.results.no_assets_created')), $html);
        // Phase 6C: a validated session offers the confirmation form, bound
        // to this validation.
        $this->assertStringContainsString('id="asset-import-execute"', $html);
        $this->assertStringContainsString('action="'.route('hardware.import.execute', $session->public_id).'"', $html);
        $this->assertStringContainsString('value="'.\App\Actions\AssetImport\ExecuteAssetImport::confirmationToken($session->fresh()).'"', $html);
        $this->assertStringNotContainsString('id="asset-import-download-errors"', $html);
        $this->assertStringContainsString(route('hardware.import.target', $session->public_id), $html);
        $this->assertStringContainsString(route('hardware.import.mapping', $session->public_id), $html);
        $this->assertStringContainsString(route('hardware.import.validate', $session->public_id), $html);
    }

    #[Test]
    public function an_invalid_session_marks_rows_lists_errors_and_cannot_proceed(): void
    {
        $user = $this->importer();
        $session = $this->validated($user, "Asset Tag,Name,Cost\nT-1,Good,1\nT-1,Dup,abc\n,Blank,2\n");

        $html = $this->page($user, $session);

        $this->assertSame(['3', '0', '3', '4'], [$this->countIn($html, 'total'), $this->countIn($html, 'valid'), $this->countIn($html, 'invalid'), $this->countIn($html, 'errors')]);
        $this->assertStringContainsString('id="asset-import-not-eligible"', $html);
        $this->assertStringNotContainsString('id="asset-import-eligible"', $html);
        $this->assertMatchesRegularExpression('/<tr class="danger" data-row="3">/', $html);
        $this->assertStringContainsString(e(trans('admin/hardware/import.validation.decimal')), $html);
        $this->assertStringContainsString('id="asset-import-download-errors"', $html);
        $this->assertStringNotContainsString('id="asset-import-execute"', $html, 'An invalid session cannot proceed.');
        $this->assertSame(AssetImportSession::STATE_VALIDATION_FAILED, $session->state);
        $this->assertNull($session->validated_at);

        $invalidOnly = $this->page($user, $session, ['filter' => 'invalid']);
        $this->assertSame(3, substr_count($invalidOnly, 'class="danger"'));
    }

    #[Test]
    public function the_preview_is_paginated_and_bounded(): void
    {
        config(['asset_import.preview_page_size' => 10]);
        $user = $this->importer();
        $lines = ['Asset Tag,Name,Cost'];
        for ($i = 1; $i <= 25; $i++) {
            $lines[] = "T-$i,Name $i,1";
        }
        $session = $this->validated($user, implode("\n", $lines)."\n");

        $first = $this->page($user, $session);
        $this->assertSame(10, preg_match_all('/<tr[^>]* data-row="/', $first));
        $this->assertStringContainsString('data-row="2"', $first);
        $this->assertStringNotContainsString('data-row="12"', $first);

        $third = $this->page($user, $session, ['page' => 3]);
        $this->assertSame(5, preg_match_all('/<tr[^>]* data-row="/', $third));
        $this->assertStringContainsString('data-row="26"', $third);

        // Out-of-range pages are clamped, never empty or unbounded.
        $this->assertSame(5, preg_match_all('/<tr[^>]* data-row="/', $this->page($user, $session, ['page' => 99])));
    }

    #[Test]
    public function every_value_is_escaped(): void
    {
        $user = $this->importer();
        $session = $this->validated($user, "Asset Tag,Name,Cost\n<b>T-1</b>,<script>alert(1)</script>,1\n\"<img src=x onerror=alert(2)>\",x,y\n");

        $html = $this->page($user, $session);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);
        $this->assertStringNotContainsString('<b>T-1</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    #[Test]
    public function multiline_notes_are_previewed_escaped_with_line_breaks(): void
    {
        $user = $this->importer();
        $session = $this->configuredSession($user, "Asset Tag,Notes\nT-1,\"Line <b>one</b>\nLine two\"\n", [], [0 => 'standard:asset_tag', 1 => 'standard:notes']);
        $this->postValidate($user, $session)->assertRedirect(route('hardware.import.results', $session->public_id));

        $html = $this->page($user, $session->fresh());

        $this->assertStringContainsString(e(trans('general.notes')), $html);
        $this->assertMatchesRegularExpression('/<td\s+class="asset-import-notes" style="white-space: pre-line;"\s*>Line &lt;b&gt;one&lt;\/b&gt;\nLine two<\/td>/', $html);
        $this->assertStringNotContainsString('<b>one</b>', $html);
        $this->assertStringContainsString('id="asset-import-eligible"', $html);
    }

    #[Test]
    public function the_error_report_has_safe_columns_and_neutralises_formulas(): void
    {
        $user = $this->importer();
        $session = $this->validated($user, "Asset Tag,Name,Cost\n=HYPERLINK(\"http://evil\"),Secret Name,abc\n+cmd,,x\n-1+2,,x\n@SUM(A1),,1\n@SUM(A1),,1\n");

        $response = $this->actingAs($user, 'web')->get(route('hardware.import.errors', $session->public_id))->assertOk();

        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/^attachment; filename="asset-import-errors-[0-9a-f]{8}\.csv"$/', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $lines = array_map('str_getcsv', array_filter(explode("\n", (string) $response->getContent())));
        $this->assertSame(['Row Number', 'Asset Tag', 'Field', 'Error'], $lines[0]);
        foreach (array_slice($lines, 1) as $line) {
            $this->assertCount(4, $line);
            foreach ($line as $cell) {
                $this->assertDoesNotMatchRegularExpression('/^[=+\-@]/', $cell, 'Cell must not start a formula: '.$cell);
            }
        }
        $this->assertSame(['2', "'=HYPERLINK(\"http://evil\")", 'Purchase Cost'], array_slice($lines[1], 0, 3));
        $this->assertSame(["'=HYPERLINK(\"http://evil\")", "'+cmd", "'-1+2", "'@SUM(A1)"], array_values(array_unique(array_column(array_slice($lines, 1), 1))));
        $this->assertStringNotContainsString('Secret Name', (string) $response->getContent(), 'No raw row data.');
        $this->assertSame([$session->storage_path], $this->storedFiles(), 'The report is never stored.');
    }

    #[Test]
    public function there_is_no_report_when_validation_found_no_errors(): void
    {
        $user = $this->importer();
        $session = $this->validated($user, "Asset Tag,Name,Cost\nT-1,One,1\n");

        $this->actingAs($user, 'web')->get(route('hardware.import.errors', $session->public_id))
            ->assertRedirect(route('hardware.import.results', $session->public_id))
            ->assertSessionHas('info', trans('admin/hardware/import.results.no_errors'));
    }

    #[Test]
    public function results_that_changed_since_validation_are_flagged_and_not_eligible(): void
    {
        $user = $this->importer();
        $session = $this->validated($user, "Asset Tag,Name,Cost\nCLASH-1,One,1\n");
        $this->assertSame(AssetImportSession::STATE_VALIDATED, $session->state);

        // Someone creates an asset with the same tag after validation.
        $this->assetIn($this->cat['leaf2'], ['asset_tag' => 'CLASH-1']);

        $html = $this->page($user, $session);
        $this->assertStringContainsString('id="asset-import-stale"', $html);
        $this->assertStringContainsString('id="asset-import-not-eligible"', $html);
    }

    #[Test]
    public function the_steps_show_six_stages_and_the_review_page_offers_validation(): void
    {
        $user = $this->importer();
        $session = $this->configuredSession($user, self::CSV, [], self::MAPPING);

        $review = (string) $this->actingAs($user, 'web')->get(route('hardware.import.review', $session->public_id))->assertOk()->getContent();
        foreach (['upload', 'target', 'mapping', 'review', 'validate', 'preview'] as $step) {
            $this->assertStringContainsString(e(trans('admin/hardware/import.steps.'.$step)), $review);
        }
        $this->assertStringContainsString('id="asset-import-validate"', $review);
    }

    #[Test]
    public function the_results_page_does_not_query_per_row(): void
    {
        $user = $this->importer();
        $small = $this->validated($user, "Asset Tag,Name,Cost\nT-1,One,1\n");
        $lines = ['Asset Tag,Name,Cost'];
        for ($i = 1; $i <= 400; $i++) {
            $lines[] = "T-$i,Name $i,1";
        }
        $large = $this->validated($user, implode("\n", $lines)."\n");

        $count = function (AssetImportSession $session) use ($user) {
            $this->page($user, $session);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->page($user, $session);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count($small), $count($large));
    }
}
