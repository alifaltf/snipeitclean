<?php

namespace Tests\Feature\Assets\Import;

use App\Models\AssetImportSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * ERS Phase 6A: CSV upload. Only well-formed, comma-separated UTF-8 CSV
 * files within the size and row limits open a session; the file is kept
 * on the private disk under a random name with its SHA-256.
 */
class AssetImportUploadTest extends TestCase
{
    use BuildsAssetImportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportFixture();
    }

    #[Test]
    public function a_valid_csv_opens_a_session_and_is_stored_privately_under_a_random_name(): void
    {
        $user = $this->importer();

        $response = $this->upload($user, $this->csvFile(self::CSV, 'Laptops 2026.csv'));

        $session = AssetImportSession::query()->sole();
        $response->assertRedirect(route('hardware.import.target', $session->public_id));

        $this->assertTrue(Str::isUuid($session->public_id));
        $this->assertSame($user->id, $session->created_by);
        $this->assertSame('Laptops 2026.csv', $session->original_filename);
        $this->assertSame(['Asset Tag', 'Serial Number', 'Laptop Model', 'Notes'], $session->headers);
        $this->assertSame(2, $session->row_count);
        $this->assertSame(strlen(self::CSV), $session->file_size);
        $this->assertSame(hash('sha256', self::CSV), $session->file_sha256);
        $this->assertSame(AssetImportSession::STATE_UPLOADED, $session->state);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $session->expires_at->timestamp, 5);

        $files = $this->storedFiles();
        $this->assertSame([$session->storage_path], $files);
        $this->assertStringStartsWith(config('asset_import.directory').'/', $session->storage_path);
        $this->assertMatchesRegularExpression('/\/[A-Za-z0-9]{40}\.csv\z/', $session->storage_path);
        $this->assertStringNotContainsString('Laptops', $session->storage_path);
        $this->assertSame(self::CSV, \App\Services\AssetImport\AssetImportSessions::disk()->get($session->storage_path));
        $this->assertFalse(str_starts_with($session->storage_path, 'public'));
    }

    #[Test]
    public function a_utf8_byte_order_mark_is_accepted_and_not_part_of_the_first_header(): void
    {
        $session = $this->newSession($this->importer(), "\xEF\xBB\xBF".self::CSV);

        $this->assertSame('Asset Tag', $session->headers[0]);
    }

    #[Test]
    public function the_original_filename_is_never_used_as_a_path_and_is_rendered_escaped(): void
    {
        $user = $this->importer();
        $this->upload($user, $this->csvFile(self::CSV, '"><img src=x onerror=alert(1)>evil.csv'))->assertRedirect();
        $session = AssetImportSession::query()->sole();

        $this->assertSame('"><img src=x onerror=alert(1)>evil.csv', $session->original_filename);
        $this->assertStringNotContainsString('evil', $session->storage_path);
        $page = $this->actingAs($user, 'web')->get(route('hardware.import.target', $session->public_id))->assertOk()->getContent();
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;evil.csv', $page);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $page);
    }

    public static function rejectedFiles(): array
    {
        $rows = "Asset Tag,Serial\n".implode("\n", array_map(fn ($i) => "T-$i,S-$i", range(1, 5001)))."\n";

        return [
            'not a .csv file' => ['assets.txt', self::CSV, 'not_csv'],
            'binary content named .csv' => ['image.csv', "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01", 'not_utf8'],
            'a non-text MIME type' => ['image.csv', null, 'not_csv'],
            'empty file' => ['empty.csv', '', 'empty'],
            'header only, no data rows' => ['header.csv', "Asset Tag,Serial\n", 'no_rows'],
            'no header (blank first row)' => ['blank.csv', ",,\nT-1,S-1,x\n", 'no_header'],
            'a blank header cell' => ['blank-cell.csv', "Asset Tag,,Serial\nT-1,x,S-1\n", 'blank_header'],
            'duplicate headers after trimming and case' => ['dupe.csv', "Serial,Asset Tag,  serial \nS-1,T-1,S-2\n", 'duplicate_header'],
            'row with an extra column' => ['extra.csv', "Asset Tag,Serial\nT-1,S-1,oops\n", 'malformed'],
            'row with a missing column' => ['short.csv', "Asset Tag,Serial\nT-1\n", 'malformed'],
            'unclosed quote' => ['quote.csv', "Asset Tag,Serial\n\"T-1,S-1\nT-2,S-2\n", 'malformed'],
            'not UTF-8' => ['latin1.csv', "Asset Tag,Name\nT-1,Caf\xE9\n", 'not_utf8'],
            'semicolon separated' => ['semicolon.csv', "Asset Tag;Serial\nT-1;S-1\n", 'not_comma_separated'],
            'more than 5,000 data rows' => ['big.csv', $rows, 'too_many_rows'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedFiles')]
    public function unacceptable_files_are_rejected_without_a_session_or_stored_file(string $name, ?string $content, string $reason): void
    {
        $user = $this->importer();
        $file = $content === null
            ? UploadedFile::fake()->create($name, 1, 'image/png')
            : UploadedFile::fake()->createWithContent($name, $content);

        $this->upload($user, $file)
            ->assertRedirect(route('hardware.import.index'))
            ->assertSessionHasErrors('csv_file');
        $expected = strtok(trans('admin/hardware/import.upload.'.$reason), ':');
        $this->assertStringStartsWith($expected, session('errors')->first('csv_file'));

        $this->assertSame(0, AssetImportSession::query()->count());
        $this->assertSame([], $this->storedFiles());
    }

    #[Test]
    public function files_over_10_mb_are_rejected(): void
    {
        $user = $this->importer();

        $this->upload($user, UploadedFile::fake()->create('big.csv', 10241, 'text/csv'))
            ->assertSessionHasErrors('csv_file');
        $this->assertSame(0, AssetImportSession::query()->count());
    }

    #[Test]
    public function exactly_5000_data_rows_are_accepted(): void
    {
        $rows = "Asset Tag,Serial\n".implode("\n", array_map(fn ($i) => "T-$i,S-$i", range(1, 5000)))."\n";

        $this->assertSame(5000, $this->newSession($this->importer(), $rows)->row_count);
    }

    #[Test]
    public function formula_like_values_are_stored_as_plain_text_and_never_evaluated(): void
    {
        $content = "Asset Tag,=HYPERLINK(\"http://x\")\n=1+1,@SUM(A1)\n";
        $user = $this->importer();
        $session = $this->newSession($user, $content);

        $this->assertSame(['Asset Tag', '=HYPERLINK("http://x")'], $session->headers);
        $this->assertSame($content, \App\Services\AssetImport\AssetImportSessions::disk()->get($session->storage_path));
    }

    #[Test]
    public function the_stored_file_is_removed_when_the_session_cannot_be_created(): void
    {
        $user = $this->importer();
        AssetImportSession::creating(function () {
            throw new RuntimeException('simulated failure');
        });

        $this->upload($user)->assertRedirect(route('hardware.import.index'))->assertSessionHas('error');

        $this->assertSame(0, AssetImportSession::query()->count());
        $this->assertSame([], $this->storedFiles());
    }
}
