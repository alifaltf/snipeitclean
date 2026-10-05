<?php

namespace Tests\Feature\Assets\Import;

use App\Models\AssetCategoryPermission;
use App\Models\AssetImportSession;
use App\Models\Company;
use App\Models\User;
use App\Services\AssetImport\AssetImportSessions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 6B: who may validate, view results and download the error
 * report, and how the session state follows validation. Every endpoint
 * re-checks owner, expiry, file integrity, permissions, target and
 * mapping; inaccessible sessions all get the same generic 404.
 */
class AssetImportValidationSecurityTest extends TestCase
{
    use BuildsAssetImportFixture;

    private const MAPPING = [0 => 'standard:asset_tag', 1 => 'standard:serial'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportFixture();
    }

    private function readySession(User $user, string $csv = self::CSV): AssetImportSession
    {
        return $this->configuredSession($user, $csv, [], self::MAPPING);
    }

    /** @return array<string, array{int, string|null}> endpoint => [status, generic message] */
    private function answers(User $user, string $publicId): array
    {
        $answers = [];
        foreach ([
            'validate' => fn () => $this->actingAs($user, 'web')->post(route('hardware.import.validate', $publicId)),
            'results' => fn () => $this->actingAs($user, 'web')->get(route('hardware.import.results', $publicId)),
            'errors' => fn () => $this->actingAs($user, 'web')->get(route('hardware.import.errors', $publicId)),
        ] as $name => $call) {
            $response = $call();
            $answers[$name] = [$response->status(), $response->exception?->getMessage()];
        }

        return $answers;
    }

    private function generic(): array
    {
        return array_fill_keys(['validate', 'results', 'errors'], [404, trans('admin/hardware/import.session_unavailable')]);
    }

    #[Test]
    public function the_owner_validates_and_the_state_records_the_outcome(): void
    {
        $user = $this->importer();
        $session = $this->readySession($user);

        $this->postValidate($user, $session)->assertRedirect(route('hardware.import.results', $session->public_id));
        $session->refresh();
        $this->assertSame(AssetImportSession::STATE_VALIDATED, $session->state);
        $this->assertNotNull($session->validated_at);
        $this->actingAs($user, 'web')->get(route('hardware.import.results', $session->public_id))->assertOk();

        // A failing file: validation_failed and validated_at cleared.
        $failing = $this->readySession($user, "Asset Tag,Serial Number,Laptop Model,Notes\nT-1,S-1,x,y\nT-1,S-2,x,y\n");
        $this->postValidate($user, $failing)->assertRedirect(route('hardware.import.results', $failing->public_id));
        $this->assertSame(AssetImportSession::STATE_VALIDATION_FAILED, $failing->fresh()->state);
        $this->assertNull($failing->fresh()->validated_at);
    }

    #[Test]
    public function results_and_report_need_a_validation_first(): void
    {
        $user = $this->importer();
        $session = $this->readySession($user);

        $this->actingAs($user, 'web')->get(route('hardware.import.results', $session->public_id))->assertRedirect(route('hardware.import.review', $session->public_id));
        $this->actingAs($user, 'web')->get(route('hardware.import.errors', $session->public_id))->assertRedirect(route('hardware.import.review', $session->public_id));
    }

    #[Test]
    public function changing_the_target_or_mapping_clears_the_validation_and_revalidation_always_works(): void
    {
        $user = $this->importer();
        $session = $this->readySession($user);
        $this->postValidate($user, $session);
        $this->assertSame(AssetImportSession::STATE_VALIDATED, $session->fresh()->state);

        $this->postMapping($user, $session, self::MAPPING)->assertSessionHasNoErrors();
        $this->assertSame(AssetImportSession::STATE_MAPPED, $session->fresh()->state);
        $this->assertNull($session->fresh()->validated_at);
        $this->actingAs($user, 'web')->get(route('hardware.import.results', $session->public_id))->assertRedirect(route('hardware.import.review', $session->public_id));

        $this->postValidate($user, $session);
        $this->postTarget($user, $session)->assertSessionHasNoErrors();
        $this->assertSame(AssetImportSession::STATE_TARGET_SELECTED, $session->fresh()->state);
        $this->assertNull($session->fresh()->validated_at);
        // Validation needs the mapping again first.
        $this->postValidate($user, $session)->assertRedirect(route('hardware.import.mapping', $session->public_id));

        $this->postMapping($user, $session, self::MAPPING);
        $this->postValidate($user, $session);
        $this->postValidate($user, $session)->assertRedirect(route('hardware.import.results', $session->public_id));
        $this->assertSame(AssetImportSession::STATE_VALIDATED, $session->fresh()->state);
    }

    #[Test]
    public function inaccessible_sessions_all_answer_with_the_same_generic_404(): void
    {
        $owner = $this->importer();
        $other = $this->importer();
        $cases = [];

        $cases['another user'] = [$other, $this->readySession($owner)];
        $cases['super admin, not owner'] = [$this->superUser(), $this->readySession($owner)];
        $expired = $this->readySession($owner);
        $expired->expires_at = now()->subMinute();
        $expired->save();
        $cases['expired'] = [$owner, $expired];
        foreach ([
            'missing file' => fn ($s) => AssetImportSessions::disk()->delete($s->storage_path),
            'modified file' => fn ($s) => AssetImportSessions::disk()->put($s->storage_path, "Asset Tag,Serial Number,Laptop Model,Notes\nX,Y,Z,W\n"),
            'emptied file' => fn ($s) => AssetImportSessions::disk()->put($s->storage_path, ''),
        ] as $case => $tamper) {
            $session = $this->readySession($owner);
            $tamper($session);
            $cases[$case] = [$owner, $session];
        }

        foreach ($cases as $case => [$user, $session]) {
            $before = $session->fresh()->getAttributes();
            $this->assertSame($this->generic(), $this->answers($user, $session->public_id), $case);
            $this->assertSame($before, $session->fresh()->getAttributes(), "$case must not change the session");
        }

        $this->assertSame($this->generic(), $this->answers($owner, (string) Str::uuid()), 'nonexistent');
        $this->assertSame($this->generic(), $this->answers($owner, 'not-a-uuid'), 'malformed');
    }

    #[Test]
    public function revoked_permissions_close_every_validation_endpoint(): void
    {
        $owner = $this->importer();

        // Global Import revoked.
        $session = $this->readySession($owner);
        $this->postValidate($owner, $session);
        $owner->permissions = json_encode(['assets.view' => '1', 'assets.create' => '1']);
        $owner->save();
        foreach ($this->answers($owner->fresh(), $session->public_id) as $endpoint => [$status]) {
            $this->assertSame(403, $status, "import revoked: $endpoint");
        }

        // Global Assets Create revoked.
        $owner = $this->importer();
        $session = $this->readySession($owner);
        $this->postValidate($owner, $session);
        $owner->permissions = json_encode(['import' => '1', 'assets.view' => '1']);
        $owner->save();
        $this->flushPermissions();
        foreach ($this->answers($owner->fresh(), $session->public_id) as $endpoint => [$status]) {
            $this->assertSame(403, $status, "assets.create revoked: $endpoint");
        }

        // Category Create revoked on the selected category (the user keeps
        // Create elsewhere, so the import is still usable): back to target.
        $owner = $this->importer([['leaf1' => ['create'], 'leaf2' => ['create']]]);
        $session = $this->readySession($owner);
        $this->postValidate($owner, $session);
        AssetCategoryPermission::query()->where('category_id', $this->cat['leaf1']->id)->update(['can_create' => false]);
        $this->flushPermissions();
        $state = $session->fresh()->state;
        foreach (['validate' => 'post', 'results' => 'get', 'errors' => 'get'] as $endpoint => $method) {
            $this->actingAs($owner, 'web')->{$method}(route('hardware.import.'.$endpoint, $session->public_id))
                ->assertRedirect(route('hardware.import.target', $session->public_id));
        }
        $this->assertSame($state, $session->fresh()->state);
    }

    #[Test]
    public function a_mapping_recorded_for_another_file_hash_is_refused(): void
    {
        $user = $this->importer();
        $session = $this->readySession($user);
        $session->mapping_file_sha256 = hash('sha256', 'other content');
        $session->save();

        foreach (['validate' => 'post', 'results' => 'get', 'errors' => 'get'] as $endpoint => $method) {
            $this->actingAs($user, 'web')->{$method}(route('hardware.import.'.$endpoint, $session->public_id))
                ->assertRedirect(route('hardware.import.mapping', $session->public_id));
        }
        $this->assertSame(AssetImportSession::STATE_MAPPED, $session->fresh()->state);
    }

    #[Test]
    public function super_admin_validates_their_own_session_on_any_live_final_category(): void
    {
        $admin = $this->superUser();
        $session = $this->configuredSession($admin, self::CSV, ['category_id' => $this->cat['other']->id, 'model_id' => $this->asset['other']->model_id], self::MAPPING);

        $this->postValidate($admin, $session)->assertRedirect(route('hardware.import.results', $session->public_id));
        $this->assertSame(AssetImportSession::STATE_VALIDATED, $session->fresh()->state);
    }

    #[Test]
    public function full_multiple_company_support_scopes_company_values(): void
    {
        $user = $this->importer();
        $mine = Company::factory()->create(['name' => 'Mine '.Str::random(5)]);
        $foreign = Company::factory()->create(['name' => 'Foreign '.Str::random(5)]);
        $mine->users()->attach($user->id);
        Company::flushCompanyIdsCache();
        $this->settings->enableMultipleFullCompanySupport();

        $session = $this->configuredSession($user, "Asset Tag,Company\nT-1,{$mine->name}\nT-2,{$foreign->name}\nT-3,\n", ['company_source' => 'column'], [0 => 'standard:asset_tag', 1 => 'standard:company']);
        $errors = $this->errorsByRow($this->validationOf($user, $session));

        $this->assertSame([], $errors[2]);
        $this->assertSame(['standard:company: '.trans('admin/hardware/import.validation.not_found')], $errors[3]);
        $this->assertSame(['standard:company: '.trans('admin/hardware/import.validation.company_required')], $errors[4]);

        // A Super Admin is not company-scoped.
        $admin = $this->superUser();
        $session = $this->configuredSession($admin, "Asset Tag,Company\nT-1,{$foreign->name}\n", ['company_source' => 'column'], [0 => 'standard:asset_tag', 1 => 'standard:company']);
        $this->assertSame([2 => []], $this->errorsByRow($this->validationOf($admin, $session)));
    }

    #[Test]
    public function array_shaped_and_malformed_inputs_fail_safely(): void
    {
        $user = $this->importer();
        $session = $this->readySession($user);
        $this->postValidate($user, $session);

        $this->actingAs($user, 'web')->get(route('hardware.import.results', [$session->public_id, 'page' => ['x'], 'filter' => ['invalid']]))->assertOk();
        $this->actingAs($user, 'web')->get(route('hardware.import.results', [$session->public_id, 'page' => '-5']))->assertOk();
        $this->actingAs($user, 'web')->get(route('hardware.import.results', [$session->public_id, 'page' => '999999']))->assertOk();
        $this->actingAs($user, 'web')->post(route('hardware.import.validate', $session->public_id), ['state' => 'validated', 'validated_at' => '2020-01-01', 'mapping' => ['x' => ['y']]])
            ->assertRedirect(route('hardware.import.results', $session->public_id));
        $this->actingAs($user, 'web')->get('/hardware/import/'.urlencode('x[]=1').'/results')->assertNotFound();
        $this->assertSame(AssetImportSession::STATE_VALIDATED, $session->fresh()->state);
    }

    #[Test]
    public function validation_writes_nothing_but_the_session_state_and_logs_no_cell_values(): void
    {
        $user = $this->importer();
        $secret = 'SECRET-'.Str::random(12);
        $session = $this->configuredSession($user, "Asset Tag,Serial Number\n$secret,$secret-S\n$secret,BAD\n", [], self::MAPPING);
        $before = $this->protectedSnapshot();
        Log::spy();

        $this->postValidate($user, $session);
        $this->actingAs($user, 'web')->get(route('hardware.import.results', $session->public_id))->assertOk();
        $this->actingAs($user, 'web')->get(route('hardware.import.errors', $session->public_id))->assertOk();

        $this->assertSame($before, $this->protectedSnapshot());
        $this->assertSame([$session->storage_path], $this->storedFiles());
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'log'] as $level) {
            Log::shouldNotHaveReceived($level, [\Mockery::on(fn ($message) => is_string($message) && str_contains($message, $secret))]);
        }
    }
}
