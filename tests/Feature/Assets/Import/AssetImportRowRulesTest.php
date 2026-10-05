<?php

namespace Tests\Feature\Assets\Import;

use App\Models\Asset;
use App\Models\AssetImportSession;
use App\Services\AssetImport\Validation\AssetImportValidator;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 6B: per-row rules for asset tags, serials, dates, numbers and
 * booleans, and how rows are read (positions, BOM, quoting, limits).
 */
class AssetImportRowRulesTest extends TestCase
{
    use BuildsAssetImportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportFixture();
    }

    private function msg(string $key, array $replace = []): string
    {
        return trans('admin/hardware/import.validation.'.$key, $replace);
    }

    /** Validate $csv (header + rows) with $mapping and return the errors by row. */
    private function errorsFor(string $csv, array $mapping, array $target = []): array
    {
        $user = $this->importer();

        return $this->errorsByRow($this->validationOf($user, $this->configuredSession($user, $csv, $target, $mapping)));
    }

    #[Test]
    public function asset_tags_are_required_unique_in_the_file_and_never_reuse_live_or_deleted_tags(): void
    {
        $live = $this->asset['leaf1'];
        $deleted = $this->assetIn($this->cat['leaf1'], ['asset_tag' => 'GONE-'.uniqid()]);
        $deleted->delete();

        $errors = $this->errorsFor(
            "Asset Tag\nNEW-1\n   \nDUP-1\ndup-1\n".strtoupper($live->asset_tag)."\n".strtolower($deleted->asset_tag)."\n ".str_repeat('x', 192)." \n",
            [0 => 'standard:asset_tag']
        );

        $this->assertSame([], $errors[2]);
        $this->assertSame(['standard:asset_tag: '.$this->msg('required')], $errors[3]);
        $this->assertSame(['standard:asset_tag: '.$this->msg('duplicate_in_file', ['rows' => '4, 5'])], $errors[4]);
        $this->assertSame($errors[4], $errors[5], 'Case-only variations are duplicates.');
        $this->assertSame(['standard:asset_tag: '.$this->msg('asset_tag_exists')], $errors[6], 'A live tag in any case is taken.');
        $this->assertSame(['standard:asset_tag: '.$this->msg('asset_tag_exists')], $errors[7], 'A deleted asset tag cannot be reused.');
        $this->assertCount(1, $errors[8], 'Longer than the stored column.');
        $this->assertStringContainsString('191', $errors[8][0]);
    }

    #[Test]
    public function serials_are_optional_but_unique_in_the_file_and_among_live_and_deleted_assets(): void
    {
        $this->settings->disableUniqueSerialNumbers();
        $live = $this->assetIn($this->cat['leaf1'], ['serial' => 'LIVE-'.uniqid()]);
        $deleted = $this->assetIn($this->cat['leaf1'], ['serial' => 'DEL-'.uniqid()]);
        $deleted->delete();

        $errors = $this->errorsFor(
            "Asset Tag,Serial Number\nT-1,\nT-2,   \nT-3,SN-A\nT-4,sn-a\nT-5,{$live->serial}\nT-6,".strtoupper($deleted->serial)."\nT-7,SN-B\n",
            [0 => 'standard:asset_tag', 1 => 'standard:serial']
        );

        $this->assertSame([], $errors[2], 'Blank serial allowed.');
        $this->assertSame([], $errors[3], 'Whitespace-only serial is blank.');
        $this->assertSame(['standard:serial: '.$this->msg('duplicate_in_file', ['rows' => '4, 5'])], $errors[4]);
        $this->assertSame($errors[4], $errors[5]);
        $this->assertSame(['standard:serial: '.$this->msg('serial_exists')], $errors[6], 'Rejected even when unique serials are not enforced by settings.');
        $this->assertSame(['standard:serial: '.$this->msg('serial_exists')], $errors[7]);
        $this->assertSame([], $errors[8]);
    }

    #[Test]
    public function dates_must_be_real_iso_dates(): void
    {
        $errors = $this->errorsFor(
            "Asset Tag,Purchase Date,EOL,Next Audit\n"
            ."T-1,2026-02-28,2030-12-31,2024-02-29\n"
            ."T-2,2026-02-30,,\n"
            ."T-3,31/01/2026,2026-1-5,2026-01-01T00:00\n"
            ."T-4,,,\n",
            [0 => 'standard:asset_tag', 1 => 'standard:purchase_date', 2 => 'standard:asset_eol_date', 3 => 'standard:next_audit_date']
        );

        $this->assertSame([], $errors[2]);
        $this->assertSame(['standard:purchase_date: '.$this->msg('date')], $errors[3]);
        $this->assertSame([
            'standard:purchase_date: '.$this->msg('date'),
            'standard:asset_eol_date: '.$this->msg('date'),
            'standard:next_audit_date: '.$this->msg('date'),
        ], $errors[4]);
        $this->assertSame([], $errors[5]);
    }

    #[Test]
    public function numbers_are_parsed_strictly(): void
    {
        $rows = [
            '1234.50,12' => [],
            '0,0' => [],
            '1e3,1' => ['purchase_cost'],
            '"1,234.50",1' => ['purchase_cost'],
            'RM100,1' => ['purchase_cost'],
            '-5,1' => ['purchase_cost'],
            '1.234,1' => ['purchase_cost'],
            'abc,1' => ['purchase_cost'],
            '10,241' => ['warranty_months'],
            '10,1.5' => ['warranty_months'],
            '10,-1' => ['warranty_months'],
            '10,twelve' => ['warranty_months'],
        ];
        $csv = "Asset Tag,Cost,Warranty\n";
        $i = 0;
        foreach (array_keys($rows) as $values) {
            $csv .= 'T-'.(++$i).','.$values."\n";
        }

        $errors = $this->errorsFor($csv, [0 => 'standard:asset_tag', 1 => 'standard:purchase_cost', 2 => 'standard:warranty_months']);

        $row = 2;
        foreach ($rows as $values => $fields) {
            $expected = array_map(fn ($f) => 'standard:'.$f.': '.$this->msg($f === 'purchase_cost' ? 'decimal' : 'warranty'), $fields);
            $this->assertSame($expected, $errors[$row++], $values);
        }
    }

    #[Test]
    public function the_boolean_parser_accepts_only_the_documented_words(): void
    {
        foreach (['1', 'true', 'yes', 'TRUE', ' Yes ', 'tRuE'] as $word) {
            $this->assertSame(1, AssetImportValidator::parseBoolean($word), $word);
        }
        foreach (['0', 'false', 'no', 'FALSE', ' No ', 'fAlSe'] as $word) {
            $this->assertSame(0, AssetImportValidator::parseBoolean($word), $word);
        }
        foreach (['', '   ', "\t", 'y', 'n', 'on', 'off', '2', '-1', '1.0', 'maybe', 'yes!'] as $word) {
            $this->assertNull(AssetImportValidator::parseBoolean($word), var_export($word, true));
        }
    }

    #[Test]
    public function valid_boolean_words_are_parsed_including_false(): void
    {
        $user = $this->importer();
        $session = $this->configuredSession($user, "Asset Tag,Requestable,BYOD\nT-1,Yes,no\nT-2, TRUE ,0\nT-3,1,False\n", [], [0 => 'standard:asset_tag', 1 => 'standard:requestable', 2 => 'standard:byod']);
        $result = $this->validationOf($user, $session);

        $this->assertTrue($result->isEligible());
        $this->assertSame([[1, 0], [1, 0], [1, 0]], array_map(fn ($row) => [$row->attributes['requestable'], $row->attributes['byod']], $result->rows));
        $this->assertSame(trans('general.no'), $result->rows[0]->display['standard:byod'], 'A valid false is kept, not dropped.');
    }

    #[Test]
    public function blank_optional_booleans_are_left_out_so_defaults_apply(): void
    {
        $user = $this->importer();
        $session = $this->configuredSession($user, "Asset Tag,Requestable,BYOD\nT-1,,\n", [], [0 => 'standard:asset_tag', 1 => 'standard:requestable', 2 => 'standard:byod']);
        $result = $this->validationOf($user, $session);

        $this->assertSame([], $this->errorsByRow($result)[2]);
        $this->assertArrayNotHasKey('requestable', $result->rows[0]->attributes, 'Blank is not "no".');
        $this->assertArrayNotHasKey('byod', $result->rows[0]->attributes);
        $this->assertArrayNotHasKey('standard:requestable', $result->rows[0]->display);
        $this->assertTrue($result->isEligible());
    }

    #[Test]
    public function whitespace_only_optional_booleans_are_blank(): void
    {
        $user = $this->importer();
        $session = $this->configuredSession($user, "Asset Tag,Requestable,BYOD\nT-1,   ,\"\t \"\n", [], [0 => 'standard:asset_tag', 1 => 'standard:requestable', 2 => 'standard:byod']);
        $result = $this->validationOf($user, $session);

        $this->assertSame([], $this->errorsByRow($result)[2]);
        $this->assertArrayNotHasKey('requestable', $result->rows[0]->attributes);
        $this->assertArrayNotHasKey('byod', $result->rows[0]->attributes);
    }

    #[Test]
    public function unmapped_booleans_are_left_out(): void
    {
        $user = $this->importer();
        $session = $this->configuredSession($user, "Asset Tag,Requestable,BYOD\nT-1,maybe,2\n", [], [0 => 'standard:asset_tag']);
        $result = $this->validationOf($user, $session);

        $this->assertSame([], $this->errorsByRow($result)[2], 'Unmapped columns are never read.');
        $this->assertArrayNotHasKey('requestable', $result->rows[0]->attributes);
        $this->assertArrayNotHasKey('byod', $result->rows[0]->attributes);
    }

    #[Test]
    public function invalid_non_empty_booleans_are_rejected(): void
    {
        $user = $this->importer();
        $session = $this->configuredSession($user, "Asset Tag,Requestable,BYOD\nT-1,y,on\nT-2,2,maybe\nT-3,1,n\n", [], [0 => 'standard:asset_tag', 1 => 'standard:requestable', 2 => 'standard:byod']);
        $result = $this->validationOf($user, $session);
        $errors = $this->errorsByRow($result);

        foreach ([2, 3] as $row) {
            $this->assertSame(['standard:requestable: '.$this->msg('boolean'), 'standard:byod: '.$this->msg('boolean')], $errors[$row], "row $row");
        }
        $this->assertSame(['standard:byod: '.$this->msg('boolean')], $errors[4]);
        $this->assertArrayNotHasKey('byod', $result->rows[2]->attributes, 'Invalid values are never converted.');
        $this->assertFalse($result->isEligible());
    }

    #[Test]
    public function notes_are_mapped_validated_and_keep_line_breaks(): void
    {
        $user = $this->importer();
        $multiline = "First line, with comma\nSecond line \"quoted\"\r\n  Third line indented";
        $csv = 'Asset Tag,Notes'."\n"
            .'T-1,"'.str_replace('"', '""', $multiline).'"'."\n"
            ."T-2,Single line\n"
            ."T-3,\n";
        $session = $this->newSession($user, $csv);
        $this->postTarget($user, $session);
        $this->postMapping($user, $session, [0 => 'standard:asset_tag', 1 => 'standard:notes'])
            ->assertRedirect(route('hardware.import.review', $session->public_id));
        $this->assertSame([0 => 'standard:asset_tag', 1 => 'standard:notes'], $session->fresh()->mappedDestinations());

        $before = $this->protectedSnapshot();
        $assets = Asset::withoutGlobalScopes()->count();
        $result = $this->validationOf($user, $session->fresh());

        $this->assertTrue($result->isEligible());
        $this->assertSame($multiline, $result->rows[0]->attributes['notes'], 'Line breaks, quotes and inner spacing survive.');
        $this->assertSame($multiline, $result->rows[0]->display['standard:notes']);
        $this->assertSame('Single line', $result->rows[1]->attributes['notes']);
        $this->assertArrayNotHasKey('notes', $result->rows[2]->attributes, 'Blank notes are left out.');
        $this->assertSame($before, $this->protectedSnapshot());
        $this->assertSame($assets, Asset::withoutGlobalScopes()->count(), 'No assets are created.');
    }

    #[Test]
    public function notes_must_fit_snipe_its_rule_and_the_text_column(): void
    {
        $user = $this->importer();
        $fits = str_repeat('a', 65535);
        $tooManyCharacters = str_repeat('a', 65536);
        // 30,000 three-byte characters: within 65,535 characters but 90,000 bytes.
        $tooManyBytes = str_repeat('€', 30000);
        $csv = "Asset Tag,Notes\nT-1,$fits\nT-2,$tooManyCharacters\nT-3,$tooManyBytes\n";
        $session = $this->configuredSession($user, $csv, [], [0 => 'standard:asset_tag', 1 => 'standard:notes']);
        $result = $this->validationOf($user, $session);
        $errors = $this->errorsByRow($result);

        $this->assertSame([], $errors[2]);
        $this->assertSame($fits, $result->rows[0]->attributes['notes'], 'The full text is kept.');
        $this->assertSame(501, mb_strlen($result->rows[0]->display['standard:notes']), 'The preview shows a bounded excerpt.');
        $this->assertCount(1, $errors[3], 'Native max rule, reported once.');
        $this->assertStringContainsString('65535', $errors[3][0]);
        $this->assertSame(['standard:notes: '.$this->msg('too_long_bytes', ['max' => '65,535'])], $errors[4]);
    }

    #[Test]
    public function rows_are_numbered_from_the_header_and_read_by_saved_column_position(): void
    {
        $user = $this->importer();
        $csv = "\xEF\xBB\xBF".'Notes,Serial Number,Asset Tag'."\n"
            .'"multi'."\n".'line, with comma",SN-1,T-1'."\n"
            ."\n"
            .'"quoted, comma",SN-2,T-2'."\n";
        $session = $this->configuredSession($user, $csv, [], [2 => 'standard:asset_tag', 1 => 'standard:serial']);

        $result = $this->validationOf($user, $session);

        $this->assertSame(2, $result->totalRows());
        $this->assertSame([2, 3], array_map(fn ($row) => $row->row, $result->rows), 'Empty lines are skipped like at upload.');
        $this->assertSame(['T-1', 'SN-1'], [$result->rows[0]->attributes['asset_tag'], $result->rows[0]->attributes['serial']]);
        $this->assertSame(['T-2', 'SN-2'], [$result->rows[1]->attributes['asset_tag'], $result->rows[1]->attributes['serial']]);
        $this->assertTrue($result->isEligible());
    }

    #[Test]
    public function five_thousand_rows_validate_with_a_bounded_number_of_queries(): void
    {
        $user = $this->importer();
        $model = $this->modelIn('leaf1');
        $lines = ['Asset Tag,Serial Number,Model,Status'];
        for ($i = 1; $i <= 5000; $i++) {
            $lines[] = "T-$i,S-$i,{$model->name},{$this->status->name}";
        }
        $session = $this->configuredSession($user, implode("\n", $lines)."\n", ['model_source' => 'column', 'status_source' => 'column'], [0 => 'standard:asset_tag', 1 => 'standard:serial', 2 => 'standard:model', 3 => 'standard:status']);

        $count = function (AssetImportSession $session) use ($user) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $result = $this->validationOf($user, $session);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$result, $queries];
        };

        [$result, $large] = $count($session);
        $this->assertSame(5000, $result->totalRows());
        $this->assertTrue($result->isEligible());
        $this->assertLessThan(60, $large);

        $small = $this->configuredSession($user, "Asset Tag,Serial Number,Model,Status\nT-1,S-1,{$model->name},{$this->status->name}\n", ['model_source' => 'column', 'status_source' => 'column'], [0 => 'standard:asset_tag', 1 => 'standard:serial', 2 => 'standard:model', 3 => 'standard:status']);
        [, $one] = $count($small);
        // Only the chunked tag/serial lookups grow (500 values per query).
        $this->assertLessThanOrEqual($one + 2 * 10, $large);
    }

    #[Test]
    public function validating_never_writes_assets_or_master_data(): void
    {
        $user = $this->importer();
        $session = $this->configuredSession($user, "Asset Tag,Model,Status\nNEW-1,Unknown Model,Unknown Status\n", ['model_source' => 'column', 'status_source' => 'column'], [0 => 'standard:asset_tag', 1 => 'standard:model', 2 => 'standard:status']);
        $before = $this->protectedSnapshot();
        $assets = Asset::withoutGlobalScopes()->count();

        $this->postValidate($user, $session);
        $this->validationOf($user, $session);

        $this->assertSame($before, $this->protectedSnapshot());
        $this->assertSame($assets, Asset::withoutGlobalScopes()->count());
    }
}
