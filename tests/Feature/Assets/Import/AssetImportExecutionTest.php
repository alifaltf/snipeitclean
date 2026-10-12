<?php

namespace Tests\Feature\Assets\Import;

use App\Actions\AssetImport\ExecuteAssetImport;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetImportSession;
use App\Models\Location;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssetImport\AssetImportSessions;
use App\Services\AssetImport\Execution\AssetImportExecutionOutcome;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * ERS Phase 6C: ExecuteAssetImport creates every validated row as a new
 * asset through Snipe-IT's own model, or nothing at all.
 */
class AssetImportExecutionTest extends TestCase
{
    use BuildsAssetImportFixture;

    /** Master data the import must never create or change. */
    private const MASTER_TABLES = [
        'models', 'categories', 'status_labels', 'companies', 'locations', 'suppliers', 'manufacturers',
        'users', 'departments', 'custom_fields', 'custom_fieldsets', 'custom_field_custom_fieldset',
        'asset_category_permissions',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportFixture();
    }

    private function validatedSession(User $user, string $csv, array $target = [], array $mapping = [0 => 'standard:asset_tag']): AssetImportSession
    {
        $session = $this->configuredSession($user, $csv, $target, $mapping);
        $this->postValidate($user, $session)->assertRedirect(route('hardware.import.results', $session->public_id));
        $session->refresh();
        $this->assertSame(AssetImportSession::STATE_VALIDATED, $session->state, 'Fixture must validate.');

        return $session;
    }

    private function execute(User $user, AssetImportSession $session, ?string $token = null): AssetImportExecutionOutcome
    {
        $this->actingAs($user, 'web');

        return app(ExecuteAssetImport::class)->handle($user, $session->public_id, $token ?? ExecuteAssetImport::confirmationToken($session->fresh()));
    }

    private function masterSnapshot(): array
    {
        $snapshot = [];
        foreach (self::MASTER_TABLES as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    private function existingAssets(): array
    {
        return DB::table('assets')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function newAssets(array $before): \Illuminate\Support\Collection
    {
        return Asset::withoutGlobalScopes()->whereNotIn('id', array_column($before, 'id'))->orderBy('id')->get();
    }

    #[Test]
    public function a_valid_import_creates_exactly_the_validated_assets(): void
    {
        $user = $this->importer();
        $location = Location::factory()->create(['name' => 'Import Store']);
        $notes = "Line one, with comma\nLine \"two\"\r\n  indented three";
        $csv = "Asset Tag,Name,Serial Number,Notes,Requestable,BYOD,Cost\n"
            .'NEW-1,Laptop One,SN-1,"'.str_replace('"', '""', $notes).'",,yes,100.50'."\n"
            ."NEW-2,Laptop Two,,,no,,\n";
        $session = $this->validatedSession($user, $csv,
            ['location_source' => AssetImportSession::SOURCE_FIXED, 'location_id' => $location->id],
            [0 => 'standard:asset_tag', 1 => 'standard:name', 2 => 'standard:serial', 3 => 'standard:notes', 4 => 'standard:requestable', 5 => 'standard:byod', 6 => 'standard:purchase_cost']);
        $assetsBefore = $this->existingAssets();
        $masterBefore = $this->masterSnapshot();
        $validatedAt = $session->validated_at;

        $outcome = $this->execute($user, $session);

        $this->assertSame(AssetImportExecutionOutcome::COMPLETED, $outcome->status);
        $created = $this->newAssets($assetsBefore);
        $this->assertSame(['NEW-1', 'NEW-2'], $created->pluck('asset_tag')->all());
        $this->assertSame($created->pluck('id')->all(), $outcome->assetIds);
        [$one, $two] = $created->all();

        $this->assertSame('Laptop One', $one->name);
        $this->assertSame('SN-1', $one->serial);
        $this->assertSame($notes, $one->notes, 'Full multiline Notes are stored.');
        $this->assertSame('100.50', number_format((float) $one->purchase_cost, 2, '.', ''));
        $this->assertSame(0, (int) $one->requestable, 'Blank Requestable keeps the default.');
        $this->assertSame(1, (int) $one->byod);
        $this->assertSame(0, (int) $two->requestable, 'Valid "no" is stored.');
        $this->assertSame(0, (int) $two->byod, 'Blank BYOD keeps the default.');
        $this->assertNull($two->serial);
        $this->assertNull($two->notes);
        foreach ($created as $asset) {
            $this->assertSame($this->asset['leaf1']->model_id, (int) $asset->model_id);
            $this->assertSame($this->status->id, (int) $asset->status_id);
            $this->assertSame($location->id, (int) $asset->rtd_location_id, 'Default location.');
            $this->assertSame($location->id, (int) $asset->location_id, 'Current location (no checkout).');
            $this->assertNull($asset->assigned_to);
            $this->assertSame($user->id, (int) $asset->created_by, 'The importer is recorded natively.');

            $log = Actionlog::withoutGlobalScopes()->where('item_type', Asset::class)->where('item_id', $asset->id)->where('action_type', 'create')->sole();
            $this->assertSame($user->id, (int) $log->created_by);
            $this->assertSame('importer', $log->action_source);
        }

        $this->assertSame($assetsBefore, array_values(array_filter($this->existingAssets(), fn ($row) => in_array($row['id'], array_column($assetsBefore, 'id'), true))), 'Existing assets are untouched.');
        $this->assertSame($masterBefore, $this->masterSnapshot(), 'No master data is created or changed.');

        $session->refresh();
        $this->assertSame(AssetImportSession::STATE_COMPLETED, $session->state);
        $this->assertNotNull($session->completed_at);
        $this->assertSame(2, $session->imported_count);
        $this->assertSame($outcome->assetIds, $session->imported_asset_ids);
        $this->assertNull($session->execution_errors);
        $this->assertEquals($validatedAt, $session->validated_at);
    }

    #[Test]
    public function encrypted_custom_fields_are_encrypted_exactly_once_natively(): void
    {
        $user = $this->importer(extra: ['assets.view.encrypted_custom_fields' => '1']);
        $field = $this->customFieldOn($this->modelIn('leaf1'), ['name' => 'Vault', 'field_encrypted' => 1]);
        $plain = 'Secret-'.Str::random(12);
        $session = $this->validatedSession($user, "Asset Tag,Vault\nENC-1,$plain\n", [], [0 => 'standard:asset_tag', 1 => 'custom_field:'.$field->id]);
        $before = $this->existingAssets();

        $this->assertSame(AssetImportExecutionOutcome::COMPLETED, $this->execute($user, $session)->status);

        $stored = DB::table('assets')->where('id', $this->newAssets($before)->sole()->id)->value($field->db_column_name());
        $this->assertNotSame($plain, $stored);
        $this->assertStringNotContainsString($plain, (string) $stored);
        $this->assertSame($plain, Crypt::decrypt($stored), 'Decrypting once gives the plain value: encrypted exactly once.');
        $this->assertStringNotContainsString($plain, json_encode($session->fresh()->getAttributes()));
    }

    #[Test]
    public function rows_that_became_invalid_after_validation_create_nothing(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nLATE-1\nLATE-2\n");
        // Created by someone else after Phase 6B validation.
        $this->assetIn($this->cat['leaf2'], ['asset_tag' => 'late-2']);
        $before = $this->existingAssets();

        $outcome = $this->execute($user, $session);

        $this->assertSame(AssetImportExecutionOutcome::REVALIDATION_FAILED, $outcome->status);
        $this->assertSame($before, $this->existingAssets());
        $session->refresh();
        $this->assertSame(AssetImportSession::STATE_VALIDATION_FAILED, $session->state);
        $this->assertNull($session->validated_at);
        $this->assertNull($session->completed_at);
    }

    #[Test]
    public function a_native_save_failure_after_earlier_rows_rolls_everything_back(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nOK-1\nOK-2\nFAIL-3\nOK-4\n");
        Asset::saving(fn (Asset $asset) => $asset->asset_tag === 'FAIL-3' ? false : null);
        $before = $this->existingAssets();
        $logs = Actionlog::withoutGlobalScopes()->count();
        $orders = DB::table('orders')->count();
        $nextTag = DB::table('settings')->value('next_auto_tag_base');

        $outcome = $this->execute($user, $session);

        $this->assertSame(AssetImportExecutionOutcome::EXECUTION_FAILED, $outcome->status);
        $this->assertSame($before, $this->existingAssets(), 'OK-1 and OK-2 were rolled back.');
        $this->assertSame($logs, Actionlog::withoutGlobalScopes()->count(), 'Their action logs too.');
        $this->assertSame($orders, DB::table('orders')->count(), 'And their order lines.');
        $this->assertSame($nextTag, DB::table('settings')->value('next_auto_tag_base'));
        $this->assertSame($nextTag, Setting::getSettings()->next_auto_tag_base, 'The settings cache is not left stale.');

        $session->refresh();
        $this->assertSame(AssetImportSession::STATE_EXECUTION_FAILED, $session->state);
        $this->assertNull($session->validated_at, 'A retry needs a fresh validation.');
        $this->assertNull($session->completed_at);
        $this->assertNotNull($session->execution_failed_at);
        $this->assertSame(4, $session->execution_errors[0]['row']);
        $this->assertSame('FAIL-3', $session->execution_errors[0]['asset_tag']);
        $this->assertSame(trans('admin/hardware/import.execution.save_failed'), $session->execution_errors[0]['message']);
    }

    #[Test]
    public function native_model_validation_at_save_stops_the_import(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nV-1\nV-2\n");
        // The model is deleted by someone else after row 2's validation
        // but before it is saved: Snipe-IT's own model rule rejects it.
        $modelId = $this->asset['leaf1']->model_id;
        Asset::created(function (Asset $asset) use ($modelId) {
            if ($asset->asset_tag === 'V-1') {
                DB::table('models')->where('id', $modelId)->update(['deleted_at' => now()]);
            }
        });
        $before = $this->existingAssets();

        $outcome = $this->execute($user, $session);

        $this->assertSame(AssetImportExecutionOutcome::EXECUTION_FAILED, $outcome->status);
        $this->assertSame($before, $this->existingAssets());
        $this->assertNull(DB::table('models')->where('id', $modelId)->value('deleted_at'), 'Rolled back as well.');
        $error = $session->fresh()->execution_errors[0];
        $this->assertSame(3, $error['row']);
        $this->assertSame('V-2', $error['asset_tag']);
        $this->assertSame(trans('admin/hardware/form.model'), $error['field']);
    }

    #[Test]
    public function an_unexpected_exception_rolls_back_and_is_logged_without_details(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nX-1\nX-2-SECRETTAG\n");
        Asset::created(function (Asset $asset) {
            if ($asset->asset_tag === 'X-2-SECRETTAG') {
                throw new RuntimeException('boom with X-2-SECRETTAG inside');
            }
        });
        Log::spy();
        $before = $this->existingAssets();

        $outcome = $this->execute($user, $session);

        $this->assertSame(AssetImportExecutionOutcome::EXECUTION_FAILED, $outcome->status);
        $this->assertSame($before, $this->existingAssets());
        $this->assertSame([['row' => null, 'asset_tag' => null, 'field' => '', 'message' => trans('admin/hardware/import.execution.unexpected')]], $session->fresh()->execution_errors);
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => $context === ['import' => $session->public_id, 'exception' => RuntimeException::class]);
        Log::shouldNotHaveReceived('error', [\Mockery::on(fn ($m) => is_string($m) && str_contains($m, 'SECRETTAG')), \Mockery::any()]);
    }

    #[Test]
    public function a_clash_that_validation_could_not_see_is_caught_before_commit(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag,Serial Number\nC-1,CS-1\nC-2,CS-2\n", [], [0 => 'standard:asset_tag', 1 => 'standard:serial']);
        // While the import runs, another writer stores a (soft-deleted)
        // asset with C-2's tag: Snipe-IT's own rule ignores deleted assets,
        // the ERS rule does not.
        Asset::created(function (Asset $asset) {
            if ($asset->asset_tag === 'C-1') {
                DB::table('assets')->insert(['asset_tag' => 'c-2', 'model_id' => $asset->model_id, 'status_id' => $asset->status_id, 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $before = $this->existingAssets();

        $outcome = $this->execute($user, $session);

        $this->assertSame(AssetImportExecutionOutcome::EXECUTION_FAILED, $outcome->status);
        $this->assertSame($before, $this->existingAssets(), 'Everything, including the concurrent row, rolled back here.');
        $this->assertSame([['row' => 3, 'asset_tag' => 'C-2', 'field' => trans('general.asset_tag'), 'message' => trans('admin/hardware/import.execution.conflict')]], $session->fresh()->execution_errors);
    }

    #[Test]
    public function a_completed_session_never_imports_again(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nONCE-1\n");
        $token = ExecuteAssetImport::confirmationToken($session);
        $this->assertSame(AssetImportExecutionOutcome::COMPLETED, $this->execute($user, $session, $token)->status);
        $after = $this->existingAssets();

        foreach (range(1, 2) as $attempt) {
            try {
                $this->execute($user, $session, $token);
                $this->fail('A completed session must not be available.');
            } catch (NotFoundHttpException $e) {
                $this->assertSame(trans('admin/hardware/import.session_unavailable'), $e->getMessage());
            }
        }
        $this->assertSame($after, $this->existingAssets());
        $this->assertSame(1, $session->fresh()->imported_count);
    }

    #[Test]
    public function a_wrong_or_missing_confirmation_creates_nothing(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nCF-1\n");
        $before = $this->existingAssets();

        foreach ([str_repeat('0', 64), '', 'x', null, ['array']] as $token) {
            $this->actingAs($user, 'web');
            $outcome = app(ExecuteAssetImport::class)->handle($user, $session->public_id, $token);
            $this->assertSame(AssetImportExecutionOutcome::NOT_CONFIRMED, $outcome->status);
        }

        $this->assertSame($before, $this->existingAssets());
        $this->assertSame(AssetImportSession::STATE_VALIDATED, $session->fresh()->state);
    }

    #[Test]
    public function every_validation_gets_its_own_confirmation_even_within_one_second(): void
    {
        $this->freezeTime();
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nSEC-1\n");
        $first = ExecuteAssetImport::confirmationToken($session);

        // Same second, same file, target and mapping.
        $this->postValidate($user, $session);
        $second = ExecuteAssetImport::confirmationToken($session->fresh());

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
        $this->assertEquals($session->validated_at, $session->fresh()->validated_at, 'Time really did not move.');

        $before = $this->existingAssets();
        $this->assertSame(AssetImportExecutionOutcome::NOT_CONFIRMED, $this->execute($user, $session, $first)->status, 'The old token never authorises the newer validation.');
        $this->assertSame($before, $this->existingAssets());
        $this->assertSame(AssetImportExecutionOutcome::COMPLETED, $this->execute($user, $session, $second)->status);
    }

    #[Test]
    public function a_mapping_change_and_revalidation_in_the_same_second_invalidates_the_old_confirmation(): void
    {
        $this->freezeTime();
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag,Serial Number\nMAP-1,MS-1\n");
        $old = ExecuteAssetImport::confirmationToken($session);

        // Saved again with the very same mapping: still a new validation.
        $this->postMapping($user, $session, [0 => 'standard:asset_tag'])->assertSessionHasNoErrors();
        $this->assertNull(ExecuteAssetImport::confirmationToken($session->fresh()), 'Saving the mapping clears the validation.');
        $this->postValidate($user, $session);

        $this->assertSame(AssetImportExecutionOutcome::NOT_CONFIRMED, $this->execute($user, $session, $old)->status);
        $this->assertSame(0, Asset::withoutGlobalScopes()->where('asset_tag', 'MAP-1')->count());
    }

    #[Test]
    public function a_target_change_and_revalidation_in_the_same_second_invalidates_the_old_confirmation(): void
    {
        $this->freezeTime();
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nTGT-1\n");
        $old = ExecuteAssetImport::confirmationToken($session);

        $this->postTarget($user, $session)->assertSessionHasNoErrors();
        $this->assertNull(ExecuteAssetImport::confirmationToken($session->fresh()), 'Saving the target clears the validation.');
        $this->postMapping($user, $session, [0 => 'standard:asset_tag']);
        $this->postValidate($user, $session);

        $this->assertSame(AssetImportExecutionOutcome::NOT_CONFIRMED, $this->execute($user, $session, $old)->status);
        $this->assertSame(0, Asset::withoutGlobalScopes()->where('asset_tag', 'TGT-1')->count());
    }

    #[Test]
    public function failed_validation_failed_execution_and_completion_all_invalidate_the_confirmation(): void
    {
        $this->freezeTime();
        $user = $this->importer();

        // Validation that fails (data changed): no current validation.
        $session = $this->validatedSession($user, "Asset Tag\nINV-1\n");
        $old = ExecuteAssetImport::confirmationToken($session);
        $this->assetIn($this->cat['leaf2'], ['asset_tag' => 'inv-1']);
        $this->postValidate($user, $session);
        $this->assertNull($session->fresh()->getRawOriginal('validation_nonce'));
        $this->assertNull(ExecuteAssetImport::confirmationToken($session->fresh()));
        $this->assertSame(AssetImportExecutionOutcome::NOT_VALIDATED, $this->execute($user, $session, $old)->status);

        // Revalidation failing inside the execution transaction.
        $session = $this->validatedSession($user, "Asset Tag\nINV-2\n");
        $old = ExecuteAssetImport::confirmationToken($session);
        $this->assetIn($this->cat['leaf2'], ['asset_tag' => 'inv-2']);
        $this->assertSame(AssetImportExecutionOutcome::REVALIDATION_FAILED, $this->execute($user, $session, $old)->status);
        $this->assertNull($session->fresh()->getRawOriginal('validation_nonce'));

        // Rolled-back execution.
        $session = $this->validatedSession($user, "Asset Tag\nINV-3\n");
        $old = ExecuteAssetImport::confirmationToken($session);
        Asset::saving(fn (Asset $asset) => $asset->asset_tag === 'INV-3' ? false : null);
        $this->assertSame(AssetImportExecutionOutcome::EXECUTION_FAILED, $this->execute($user, $session, $old)->status);
        $this->assertNull($session->fresh()->getRawOriginal('validation_nonce'));
        $this->assertSame(AssetImportExecutionOutcome::NOT_VALIDATED, $this->execute($user, $session, $old)->status);

        // Completion.
        $session = $this->validatedSession($user, "Asset Tag\nINV-4\n");
        $this->assertSame(AssetImportExecutionOutcome::COMPLETED, $this->execute($user, $session)->status);
        $this->assertNull(DB::table('asset_import_sessions')->where('id', $session->id)->value('validation_nonce'));

        $this->assertSame(1, Asset::withoutGlobalScopes()->where('asset_tag', 'like', 'INV-%')->whereNull('deleted_at')->whereNotIn('asset_tag', ['inv-1', 'inv-2'])->count(), 'Only INV-4 was created.');
    }

    #[Test]
    public function the_validation_nonce_never_leaves_the_server(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nNN-1\n");
        $nonce = DB::table('asset_import_sessions')->where('id', $session->id)->value('validation_nonce');

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $nonce);
        $this->assertArrayNotHasKey('validation_nonce', $session->fresh()->toArray());
        $html = (string) $this->actingAs($user, 'web')->get(route('hardware.import.results', $session->public_id))->getContent();
        $this->assertStringNotContainsString($nonce, $html);
        $this->assertStringContainsString(ExecuteAssetImport::confirmationToken($session->fresh()), $html);
    }

    #[Test]
    public function only_validated_sessions_can_run(): void
    {
        $user = $this->importer();
        $mapped = $this->configuredSession($user, "Asset Tag\nNV-1\n", [], [0 => 'standard:asset_tag']);
        $failed = $this->configuredSession($user, "Asset Tag\nDUP\nDUP\n", [], [0 => 'standard:asset_tag']);
        $this->postValidate($user, $failed);
        $before = $this->existingAssets();

        $this->assertSame(AssetImportExecutionOutcome::NOT_VALIDATED, $this->execute($user, $mapped, str_repeat('a', 64))->status);
        $this->assertSame(AssetImportExecutionOutcome::NOT_VALIDATED, $this->execute($user, $failed->fresh(), str_repeat('a', 64))->status);
        $this->assertSame($before, $this->existingAssets());
    }

    #[Test]
    public function unavailable_or_unauthorised_sessions_create_nothing(): void
    {
        $owner = $this->importer();
        $before = $this->existingAssets();
        $cases = [];

        $cases['another user'] = [$this->importer(), $this->validatedSession($owner, "Asset Tag\nA-1\n"), NotFoundHttpException::class];
        $cases['super admin, not owner'] = [$this->superUser(), $this->validatedSession($owner, "Asset Tag\nA-2\n"), NotFoundHttpException::class];
        $expired = $this->validatedSession($owner, "Asset Tag\nA-3\n");
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $cases['expired'] = [$owner, $expired, NotFoundHttpException::class];
        $changed = $this->validatedSession($owner, "Asset Tag\nA-4\n");
        AssetImportSessions::disk()->put($changed->storage_path, "Asset Tag\nA-4\nEXTRA\n");
        $cases['changed file'] = [$owner, $changed, NotFoundHttpException::class];
        $missing = $this->validatedSession($owner, "Asset Tag\nA-5\n");
        AssetImportSessions::disk()->delete($missing->storage_path);
        $cases['missing file'] = [$owner, $missing, NotFoundHttpException::class];

        foreach ($cases as $case => [$user, $session, $expected]) {
            try {
                $this->execute($user, $session, ExecuteAssetImport::confirmationToken($session));
                $this->fail("$case: must be unavailable");
            } catch (NotFoundHttpException $e) {
                $this->assertSame(trans('admin/hardware/import.session_unavailable'), $e->getMessage(), $case);
            }
        }

        // Category Create revoked after validation (Create kept elsewhere):
        // the target is no longer valid, so the import goes back to it.
        $keeper = $this->importer([['leaf1' => ['create'], 'leaf2' => ['create']]]);
        $revoked = $this->validatedSession($keeper, "Asset Tag\nA-6\n");
        DB::table('asset_category_permissions')->where('category_id', $this->cat['leaf1']->id)->update(['can_create' => false]);
        $this->flushPermissions();
        $outcome = $this->execute($keeper, $revoked);
        $this->assertSame(AssetImportExecutionOutcome::NEEDS_RECONFIGURATION, $outcome->status);
        $this->assertSame('target', $outcome->problem);
        $this->assertSame(AssetImportSession::STATE_VALIDATED, $revoked->fresh()->state, 'Nothing changed.');

        $this->assertSame($before, $this->existingAssets());
    }

    #[Test]
    public function a_changed_mapping_or_target_needs_a_new_validation(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag,Serial Number\nM-1,MS-1\n");
        $token = ExecuteAssetImport::confirmationToken($session);
        $this->postMapping($user, $session, [0 => 'standard:asset_tag', 1 => 'standard:serial']);
        $this->assertSame(AssetImportExecutionOutcome::NOT_VALIDATED, $this->execute($user, $session, $token)->status);

        $other = $this->validatedSession($user, "Asset Tag\nM-2\n");
        $token = ExecuteAssetImport::confirmationToken($other);
        $this->postTarget($user, $other);
        $this->assertSame(AssetImportExecutionOutcome::NOT_VALIDATED, $this->execute($user, $other, $token)->status);

        $this->assertSame(0, Asset::withoutGlobalScopes()->whereIn('asset_tag', ['M-1', 'M-2'])->count());
    }

    #[Test]
    public function a_failed_import_can_be_retried_after_validating_again(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nR-1\nR-2\n");
        $fail = true;
        Asset::saving(function (Asset $asset) use (&$fail) {
            return $fail && $asset->asset_tag === 'R-2' ? false : null;
        });
        $this->assertSame(AssetImportExecutionOutcome::EXECUTION_FAILED, $this->execute($user, $session)->status);
        $this->assertSame(AssetImportExecutionOutcome::NOT_VALIDATED, $this->execute($user, $session, str_repeat('a', 64))->status, 'No retry without validating again.');

        $fail = false;
        $this->postValidate($user, $session)->assertRedirect(route('hardware.import.results', $session->public_id));
        $session->refresh();
        $this->assertNull($session->execution_errors, 'Validation clears the old diagnostics.');

        $this->assertSame(AssetImportExecutionOutcome::COMPLETED, $this->execute($user, $session)->status);
        $this->assertSame(2, Asset::withoutGlobalScopes()->whereIn('asset_tag', ['R-1', 'R-2'])->count());
    }

    #[Test]
    public function nothing_external_is_sent_whether_the_import_succeeds_or_fails(): void
    {
        Notification::fake();
        Mail::fake();
        Queue::fake();
        $user = $this->importer();

        $ok = $this->validatedSession($user, "Asset Tag\nFX-1\n");
        $this->assertSame(AssetImportExecutionOutcome::COMPLETED, $this->execute($user, $ok)->status);

        $bad = $this->validatedSession($user, "Asset Tag\nFX-2\nFX-3\n");
        Asset::saving(fn (Asset $asset) => $asset->asset_tag === 'FX-3' ? false : null);
        $this->assertSame(AssetImportExecutionOutcome::EXECUTION_FAILED, $this->execute($user, $bad)->status);

        Notification::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }
}
