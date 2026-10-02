<?php

namespace Tests\Feature\Assets\Import;

use App\Models\AssetImportSession;
use App\Services\AssetImport\AssetImportSessions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 6A: import sessions are addressed by a random public id, are
 * usable only by their owner while open and unexpired, store the mapping
 * by column position and are tied to the stored file's SHA-256.
 */
class AssetImportSessionTest extends TestCase
{
    use BuildsAssetImportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpImportFixture();
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function sessionRoutes(string $publicId): array
    {
        return [
            'target' => ['get', route('hardware.import.target', $publicId)],
            'target.update' => ['post', route('hardware.import.target.update', $publicId)],
            'mapping' => ['get', route('hardware.import.mapping', $publicId)],
            'mapping.update' => ['post', route('hardware.import.mapping.update', $publicId)],
            'review' => ['get', route('hardware.import.review', $publicId)],
        ];
    }

    /** @return array<string, array{int, string}> route => [status, body] */
    private function answers($user, string $publicId): array
    {
        $answers = [];
        foreach ($this->sessionRoutes($publicId) as $name => [$method, $url]) {
            $data = $method === 'post' ? $this->targetInput() + ['mapping' => [0 => 'standard:asset_tag']] : [];
            $response = $this->actingAs($user, 'web')->{$method}($url, $data);
            $answers[$name] = [$response->status(), $response->exception?->getMessage()];
        }

        return $answers;
    }

    #[Test]
    public function sessions_use_a_random_uuid_and_never_the_database_id(): void
    {
        $user = $this->importer();
        $first = $this->newSession($user);
        $second = $this->newSession($user);

        $this->assertTrue(Str::isUuid($first->public_id));
        $this->assertNotSame($first->public_id, $second->public_id);
        $this->assertStringEndsWith('/hardware/import/'.$first->public_id.'/target', route('hardware.import.target', $first->public_id));

        // The integer id is not a valid address.
        foreach ($this->answers($user, (string) $first->id) as $route => $answer) {
            $this->assertSame(404, $answer[0], $route);
        }
    }

    #[Test]
    public function the_owner_can_use_every_step(): void
    {
        $user = $this->importer();
        $session = $this->mappedSession($user);

        foreach (['hardware.import.target', 'hardware.import.mapping', 'hardware.import.review'] as $route) {
            $this->actingAs($user, 'web')->get(route($route, $session->public_id))->assertOk();
        }
        $this->actingAs($user, 'web')->get(route('hardware.import.index'))->assertOk()->assertSee($session->original_filename);
    }

    #[Test]
    public function another_users_session_answers_exactly_like_a_missing_one(): void
    {
        $owner = $this->importer();
        $session = $this->mappedSession($owner);
        $before = $session->fresh()->getAttributes();

        $missing = (string) Str::uuid();
        foreach ([$this->importer(), $this->superUser()] as $other) {
            $theirs = $this->answers($other, $session->public_id);
            $this->assertSame($this->answers($other, $missing), $theirs);
            $this->assertSame(array_fill_keys(array_keys($theirs), [404, trans('admin/hardware/import.session_unavailable')]), $theirs);
            $this->assertSame([404, trans('admin/hardware/import.session_unavailable')], $this->answers($other, 'not-a-uuid')['target']);

            $this->actingAs($other, 'web')->get(route('hardware.import.index'))->assertOk()->assertDontSee($session->public_id);
        }

        $this->assertSame($before, $session->fresh()->getAttributes(), 'Nothing may change.');
    }

    #[Test]
    public function an_expired_session_is_unavailable(): void
    {
        $user = $this->importer();
        $session = $this->mappedSession($user);

        $this->travel((int) config('asset_import.session_lifetime_hours'))->hours();
        $this->travel(1)->minutes();

        foreach ($this->answers($user, $session->public_id) as $route => $answer) {
            $this->assertSame(404, $answer[0], $route);
        }
        $this->actingAs($user, 'web')->get(route('hardware.import.index'))->assertOk()->assertDontSee($session->public_id);
    }

    #[Test]
    public function the_lifetime_comes_from_configuration(): void
    {
        config(['asset_import.session_lifetime_hours' => 2]);
        $session = $this->newSession($this->importer());

        $this->assertEqualsWithDelta(now()->addHours(2)->timestamp, $session->expires_at->timestamp, 5);
    }

    #[Test]
    public function sessions_in_a_later_or_finished_state_are_not_available_to_phase_6a_steps(): void
    {
        $user = $this->importer();
        $session = $this->mappedSession($user);

        foreach ([AssetImportSession::STATE_VALIDATED, AssetImportSession::STATE_IMPORTING, AssetImportSession::STATE_COMPLETED, AssetImportSession::STATE_FAILED, AssetImportSession::STATE_EXPIRED] as $state) {
            $session->state = $state;
            $session->save();
            foreach ($this->answers($user, $session->public_id) as $route => $answer) {
                $this->assertSame(404, $answer[0], "$state $route");
            }
        }
    }

    #[Test]
    public function the_mapping_is_stored_by_column_position(): void
    {
        $user = $this->importer();
        $session = $this->newSession($user, "Serial Number,Asset Tag,Ignored,Name\nS-1,T-1,x,Laptop\n");
        $this->postTarget($user, $session);

        $this->postMapping($user, $session, [3 => 'standard:name', 0 => 'standard:serial', 1 => 'standard:asset_tag'])->assertSessionHasNoErrors();

        $this->assertSame([
            ['column' => 0, 'header' => 'Serial Number', 'destination' => 'standard:serial'],
            ['column' => 1, 'header' => 'Asset Tag', 'destination' => 'standard:asset_tag'],
            ['column' => 2, 'header' => 'Ignored', 'destination' => null],
            ['column' => 3, 'header' => 'Name', 'destination' => 'standard:name'],
        ], $session->fresh()->mapping);
    }

    /** Every session-specific step answers a missing or modified file with the generic 404 and changes nothing. */
    #[Test]
    public function a_missing_or_modified_stored_file_closes_every_step_without_changing_anything(): void
    {
        $user = $this->importer();
        $generic = [404, trans('admin/hardware/import.session_unavailable')];

        foreach ([
            'missing' => fn (AssetImportSession $s) => AssetImportSessions::disk()->delete($s->storage_path),
            'modified' => fn (AssetImportSession $s) => AssetImportSessions::disk()->put($s->storage_path, "Asset Tag,Serial Number,Laptop Model,Notes\nX-1,X-2,X-3,X-4\n"),
            'truncated' => fn (AssetImportSession $s) => AssetImportSessions::disk()->put($s->storage_path, ''),
        ] as $case => $tamper) {
            $session = $this->mappedSession($user);
            $tamper($session);
            $before = $session->fresh()->getAttributes();
            $tables = $this->protectedSnapshot();

            $answers = $this->answers($user, $session->public_id);

            $this->assertSame(array_fill_keys(array_keys($answers), $generic), $answers, $case);
            $this->assertSame($before, $session->fresh()->getAttributes(), "$case: target, mapping, mapping hash and state must not change");
            $this->assertSame($tables, $this->protectedSnapshot(), "$case: no asset or master data may change");
        }
    }

    #[Test]
    public function an_intact_file_continues_normally_on_every_step(): void
    {
        $user = $this->importer();
        $session = $this->mappedSession($user);

        foreach ($this->answers($user, $session->public_id) as $route => [$status]) {
            $this->assertContains($status, [200, 302], $route);
        }
        $this->assertSame(AssetImportSession::STATE_MAPPED, $session->fresh()->state);
    }

    #[Test]
    public function unavailable_sessions_disclose_no_record_details(): void
    {
        $owner = $this->importer();
        $sessions = [
            'foreign' => $this->mappedSession($owner),
            'expired' => $this->mappedSession($owner),
            'missing file' => $this->mappedSession($owner),
            'modified file' => $this->mappedSession($owner),
        ];
        $sessions['expired']->expires_at = now()->subMinute();
        $sessions['expired']->save();
        AssetImportSessions::disk()->delete($sessions['missing file']->storage_path);
        AssetImportSessions::disk()->put($sessions['modified file']->storage_path, "a,b\n1,2\n");

        $other = $this->importer();
        $cases = [
            'nonexistent' => [$owner, (string) Str::uuid()],
            'foreign' => [$other, $sessions['foreign']->public_id],
            'expired' => [$owner, $sessions['expired']->public_id],
            'missing file' => [$owner, $sessions['missing file']->public_id],
            'modified file' => [$owner, $sessions['modified file']->public_id],
        ];

        $bodies = [];
        foreach ($cases as $case => [$user, $publicId]) {
            $response = $this->actingAs($user, 'web')->get(route('hardware.import.mapping', $publicId))->assertNotFound();
            $body = (string) $response->getContent();
            foreach (['Laptop Model', 'assets.csv', $this->cat['leaf1']->name, $this->modelIn('leaf1')->name, 'asset-imports/'] as $detail) {
                $this->assertStringNotContainsString($detail, $body, "$case reveals $detail");
            }
            $bodies[$case] = $response->exception?->getMessage();
        }
        $this->assertCount(1, array_unique($bodies));
    }

    #[Test]
    public function the_mapping_is_tied_to_the_file_hash(): void
    {
        $user = $this->importer();
        $session = $this->mappedSession($user);
        $this->assertSame($session->file_sha256, $session->mapping_file_sha256);

        // A mapping recorded for different file content is not accepted.
        $session->mapping_file_sha256 = hash('sha256', 'something else');
        $session->save();
        $this->actingAs($user, 'web')->get(route('hardware.import.review', $session->public_id))->assertRedirect(route('hardware.import.mapping', $session->public_id));

        // A stored file that no longer matches its hash closes the session.
        AssetImportSessions::disk()->put($session->storage_path, "Asset Tag,Serial Number,Laptop Model,Notes\nT-9,S-9,Other,changed\n");
        $this->actingAs($user, 'web')->get(route('hardware.import.review', $session->public_id))->assertNotFound();
        $this->postMapping($user, $session, [0 => 'standard:asset_tag'])->assertNotFound();
        $this->assertSame(hash('sha256', 'something else'), $session->fresh()->mapping_file_sha256);

        AssetImportSessions::disk()->delete($session->storage_path);
        $this->actingAs($user, 'web')->get(route('hardware.import.review', $session->public_id))->assertNotFound();
    }
}
