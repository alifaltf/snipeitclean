<?php

namespace Tests\Feature\Assets\Import;

use App\Actions\AssetImport\ExecuteAssetImport;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Asset;
use App\Models\AssetCategoryPermission;
use App\Models\AssetImportSession;
use App\Models\User;
use App\Services\AssetImport\AssetImportSessions;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 6C: the confirmation and execution flow over HTTP: POST with
 * CSRF, owner/permission/integrity checks, all-or-nothing results,
 * repeated submissions, safe result pages and error downloads.
 */
class AssetImportExecutionHttpTest extends TestCase
{
    use BuildsAssetImportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportFixture();
    }

    private function validatedSession(User $user, string $csv, array $mapping = [0 => 'standard:asset_tag']): AssetImportSession
    {
        $session = $this->configuredSession($user, $csv, [], $mapping);
        $this->postValidate($user, $session)->assertRedirect(route('hardware.import.results', $session->public_id));

        return $session->fresh();
    }

    private function postExecute(User $user, AssetImportSession $session, ?array $input = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'web')->post(route('hardware.import.execute', $session->public_id), $input ?? [
            'confirm' => '1',
            'confirmation' => ExecuteAssetImport::confirmationToken($session->fresh()),
        ]);
    }

    private function assetCount(): int
    {
        return Asset::withoutGlobalScopes()->withTrashed()->count();
    }

    private function generic404(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertNotFound();
        $this->assertSame(trans('admin/hardware/import.session_unavailable'), $response->exception?->getMessage());
    }

    #[Test]
    public function the_execute_route_is_post_only_and_csrf_protected(): void
    {
        $route = Route::getRoutes()->getByName('hardware.import.execute');
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());

        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nG-1\n");
        $this->actingAs($user, 'web')->get('/hardware/import/'.$session->public_id.'/execute')->assertStatus(405);

        // The web group's CSRF middleware, with the test-suite bypass off,
        // rejects a POST without a token.
        $csrf = new class(app(), app('encrypter')) extends VerifyCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
        $request = Request::create(route('hardware.import.execute', $session->public_id), 'POST', ['confirm' => '1']);
        $request->setLaravelSession(app('session.store'));
        $this->expectException(TokenMismatchException::class);
        $csrf->handle($request, fn () => response('should not run'));
    }

    #[Test]
    public function a_confirmed_import_creates_the_assets_and_shows_a_safe_result(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag,Name\nOK-1,One\n<b>OK-2</b>,Two\n", [0 => 'standard:asset_tag', 1 => 'standard:name']);
        $before = $this->assetCount();

        $this->postExecute($user, $session)
            ->assertRedirect(route('hardware.import.index'))
            ->assertSessionHas('success', trans_choice('admin/hardware/import.execution.completed', 2, ['count' => 2]));

        $this->assertSame($before + 2, $this->assetCount());
        $this->assertSame(AssetImportSession::STATE_COMPLETED, $session->fresh()->state);

        $html = (string) $this->actingAs($user, 'web')
            ->withSession(['asset_import_completed' => ['count' => 2, 'file' => 'assets.csv', 'asset_ids' => $session->fresh()->imported_asset_ids]])
            ->get(route('hardware.import.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-count="created">2</', $html);
        $this->assertStringContainsString('OK-1', $html);
        $this->assertStringContainsString('&lt;b&gt;OK-2&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>OK-2</b>', $html);
        $this->assertStringNotContainsString(route('hardware.import.results', $session->public_id), $html, 'A completed session is no longer offered.');
    }

    #[Test]
    public function the_confirmation_is_required_and_must_match_the_reviewed_validation(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nCF-1\n");
        $before = $this->assetCount();

        $this->postExecute($user, $session, ['confirmation' => ExecuteAssetImport::confirmationToken($session)])
            ->assertSessionHasErrors(['confirm' => trans('admin/hardware/import.execution.confirm_required')]);
        $this->postExecute($user, $session, ['confirm' => '1'])->assertSessionHasErrors('confirmation');
        $this->postExecute($user, $session, ['confirm' => '1', 'confirmation' => ['x']])->assertSessionHasErrors('confirmation');
        $this->postExecute($user, $session, ['confirm' => '1', 'confirmation' => str_repeat('0', 64)])
            ->assertRedirect(route('hardware.import.results', $session->public_id))
            ->assertSessionHas('warning', trans('admin/hardware/import.execution.not_confirmed'));

        $this->assertSame($before, $this->assetCount());
    }

    #[Test]
    public function repeated_submissions_create_nothing_more(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nREP-1\nREP-2\n");
        $input = ['confirm' => '1', 'confirmation' => ExecuteAssetImport::confirmationToken($session)];

        $this->postExecute($user, $session, $input)->assertRedirect(route('hardware.import.index'));
        $after = $this->assetCount();

        // Double click, browser retry, back button + resubmit.
        $this->generic404($this->postExecute($user, $session, $input));
        $this->generic404($this->postExecute($user, $session, $input));
        $this->generic404($this->actingAs($user, 'web')->get(route('hardware.import.results', $session->public_id)));

        $this->assertSame($after, $this->assetCount());
        $this->assertSame(2, $session->fresh()->imported_count);
    }

    #[Test]
    public function unavailable_sessions_answer_with_the_generic_404_and_create_nothing(): void
    {
        $owner = $this->importer();
        $before = $this->assetCount();

        $foreign = $this->validatedSession($owner, "Asset Tag\nU-1\n");
        $this->generic404($this->postExecute($this->importer(), $foreign, ['confirm' => '1', 'confirmation' => ExecuteAssetImport::confirmationToken($foreign)]));
        $this->generic404($this->postExecute($this->superUser(), $foreign, ['confirm' => '1', 'confirmation' => ExecuteAssetImport::confirmationToken($foreign)]));

        $expired = $this->validatedSession($owner, "Asset Tag\nU-2\n");
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->generic404($this->postExecute($owner, $expired));

        $modified = $this->validatedSession($owner, "Asset Tag\nU-3\n");
        AssetImportSessions::disk()->put($modified->storage_path, "Asset Tag\nU-3\nU-4\n");
        $this->generic404($this->postExecute($owner, $modified));

        $emptied = $this->validatedSession($owner, "Asset Tag\nU-5\n");
        AssetImportSessions::disk()->put($emptied->storage_path, '');
        $this->generic404($this->postExecute($owner, $emptied));

        $this->generic404($this->actingAs($owner, 'web')->post('/hardware/import/not-a-uuid/execute', ['confirm' => '1', 'confirmation' => str_repeat('a', 64)]));

        $this->assertSame($before, $this->assetCount());
    }

    #[Test]
    public function revoked_permissions_stop_the_import(): void
    {
        $before = $this->assetCount();

        // Global Import revoked (route middleware).
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nP-1\n");
        $user->permissions = json_encode(array_merge(json_decode($user->permissions, true) ?? [], ['import' => '0']));
        $user->save();
        $this->postExecute($user->fresh(), $session)->assertForbidden();

        // Global Assets Create revoked.
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nP-2\n");
        $user->permissions = json_encode(array_merge(json_decode($user->permissions, true) ?? [], ['assets.create' => '0']));
        $user->save();
        $this->flushPermissions();
        $this->postExecute($user->fresh(), $session)->assertForbidden();

        // Category Create revoked on the selected category: back to target.
        $user = $this->importer([['leaf1' => ['create'], 'leaf2' => ['create']]]);
        $session = $this->validatedSession($user, "Asset Tag\nP-3\n");
        AssetCategoryPermission::query()->where('category_id', $this->cat['leaf1']->id)->update(['can_create' => false]);
        $this->flushPermissions();
        $this->postExecute($user, $session)->assertRedirect(route('hardware.import.target', $session->public_id));

        $this->assertSame($before, $this->assetCount());
    }

    #[Test]
    public function a_changed_mapping_or_unvalidated_session_cannot_run(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag,Serial Number\nCM-1,S-1\n");
        $token = ExecuteAssetImport::confirmationToken($session);
        $this->postMapping($user, $session, [0 => 'standard:asset_tag', 1 => 'standard:serial']);

        $this->postExecute($user, $session, ['confirm' => '1', 'confirmation' => $token])
            ->assertRedirect(route('hardware.import.results', $session->public_id))
            ->assertSessionHas('warning', trans('admin/hardware/import.execution.not_validated'));
        $this->assertSame(0, Asset::withoutGlobalScopes()->where('asset_tag', 'CM-1')->count());
    }

    #[Test]
    public function data_that_changed_after_validation_fails_safely_and_hides_the_form(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nCH-1\nCH-2\n");
        // Stale page: the form is gone as soon as the data no longer passes.
        $this->assetIn($this->cat['leaf2'], ['asset_tag' => 'ch-2']);
        $html = (string) $this->actingAs($user, 'web')->get(route('hardware.import.results', $session->public_id))->getContent();
        $this->assertStringContainsString('id="asset-import-stale"', $html);
        $this->assertStringNotContainsString('id="asset-import-execute"', $html);
        $before = $this->assetCount();

        // Submitting the old form anyway.
        $this->postExecute($user, $session)
            ->assertRedirect(route('hardware.import.results', $session->public_id))
            ->assertSessionHas('error', trans('admin/hardware/import.execution.revalidation_failed'));

        $this->assertSame($before, $this->assetCount());
        $this->assertSame(AssetImportSession::STATE_VALIDATION_FAILED, $session->fresh()->state);
    }

    #[Test]
    public function a_rolled_back_import_shows_safe_diagnostics_and_a_safe_report(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nRB-1\n=HYPERLINK(\"x\")\n");
        Asset::saving(fn (Asset $asset) => str_starts_with((string) $asset->asset_tag, '=') ? false : null);
        $before = $this->assetCount();

        $this->postExecute($user, $session)
            ->assertRedirect(route('hardware.import.results', $session->public_id))
            ->assertSessionHas('error', trans('admin/hardware/import.execution.failed'));
        $this->assertSame($before, $this->assetCount(), 'Zero created after rollback.');

        $html = (string) $this->actingAs($user, 'web')->get(route('hardware.import.results', $session->public_id))->assertOk()->getContent();
        $this->assertStringContainsString('id="asset-import-execution-failed"', $html);
        $this->assertStringContainsString(e('=HYPERLINK("x")'), $html);
        $this->assertStringNotContainsString('id="asset-import-execute"', $html, 'Validate again before retrying.');
        $this->assertStringContainsString(route('hardware.import.execution_errors', $session->public_id), $html);

        $response = $this->actingAs($user, 'web')->get(route('hardware.import.execution_errors', $session->public_id))->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/^attachment; filename="asset-import-execution-errors-[0-9a-f]{8}\.csv"$/', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $lines = array_map('str_getcsv', array_filter(explode("\n", (string) $response->getContent())));
        $this->assertSame(['Row Number', 'Asset Tag', 'Field', 'Error'], $lines[0]);
        $this->assertSame(['3', "'=HYPERLINK(\"x\")", '', trans('admin/hardware/import.execution.save_failed')], $lines[1]);
        $this->assertSame([$session->storage_path], $this->storedFiles(), 'The report is never stored.');

        // Other users never see it.
        $this->generic404($this->actingAs($this->importer(), 'web')->get(route('hardware.import.execution_errors', $session->public_id)));
    }

    #[Test]
    public function there_is_no_execution_report_without_a_failed_import(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nNR-1\n");

        $this->actingAs($user, 'web')->get(route('hardware.import.execution_errors', $session->public_id))
            ->assertRedirect(route('hardware.import.results', $session->public_id))
            ->assertSessionHas('info', trans('admin/hardware/import.execution.no_errors'));
    }

    #[Test]
    public function a_failed_import_can_be_retried_after_validating_again(): void
    {
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nRT-1\nRT-2\n");
        $fail = true;
        Asset::saving(function (Asset $asset) use (&$fail) {
            return $fail && $asset->asset_tag === 'RT-2' ? false : null;
        });
        $this->postExecute($user, $session)->assertSessionHas('error');
        $this->assertSame(0, Asset::withoutGlobalScopes()->where('asset_tag', 'like', 'RT-%')->count());

        $fail = false;
        $this->postValidate($user, $session)->assertRedirect(route('hardware.import.results', $session->public_id));
        $html = (string) $this->actingAs($user, 'web')->get(route('hardware.import.results', $session->public_id))->getContent();
        $this->assertStringNotContainsString('id="asset-import-execution-failed"', $html);
        $this->assertStringContainsString('id="asset-import-execute"', $html);

        $this->postExecute($user, $session)->assertRedirect(route('hardware.import.index'));
        $this->assertSame(2, Asset::withoutGlobalScopes()->where('asset_tag', 'like', 'RT-%')->count());
    }

    #[Test]
    public function the_completed_result_lists_only_the_users_new_assets(): void
    {
        // Create only on leaf1, View nowhere (allowed for importing).
        $user = $this->importer();
        $session = $this->validatedSession($user, "Asset Tag\nMINE-1\nMINE-2\n");
        $this->postExecute($user, $session)->assertRedirect(route('hardware.import.index'));
        $mine = $session->fresh()->imported_asset_ids;
        $foreign = $this->assetIn($this->cat['leaf1'], ['asset_tag' => 'FOREIGN-'.uniqid()]);

        $html = (string) $this->actingAs($user, 'web')
            ->withSession(['asset_import_completed' => ['count' => 2, 'file' => 'x.csv', 'asset_ids' => [...$mine, $foreign->id, 'x']]])
            ->get(route('hardware.import.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-count="created">2</', $html);
        $this->assertStringContainsString('MINE-1', $html);
        $this->assertStringContainsString('MINE-2', $html);
        $this->assertStringNotContainsString($foreign->asset_tag, $html, 'Only assets this user created are listed.');
        $this->assertStringNotContainsString(route('hardware.show', $mine[0]), $html, 'No link without category View.');

        $viewer = $this->importer([['leaf1' => ['create', 'view']]]);
        $other = $this->validatedSession($viewer, "Asset Tag\nLINK-1\n");
        $this->postExecute($viewer, $other)->assertRedirect(route('hardware.import.index'));
        $id = $other->fresh()->imported_asset_ids[0];
        $html = (string) $this->actingAs($viewer, 'web')
            ->withSession(['asset_import_completed' => ['count' => 1, 'file' => 'y.csv', 'asset_ids' => [$id]]])
            ->get(route('hardware.import.index'))->getContent();
        $this->assertStringContainsString('href="'.route('hardware.show', $id).'"', $html, 'Linked when the user may view it.');
    }
}
