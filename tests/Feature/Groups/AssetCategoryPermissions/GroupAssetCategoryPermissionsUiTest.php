<?php

namespace Tests\Feature\Groups\AssetCategoryPermissions;

use App\Actions\Groups\SaveGroupAssetCategoryPermissionsAction;
use App\Models\AssetCategoryPermission;
use App\Models\Group;
use App\Models\User;
use App\Services\AssetCategoryAccess;
use DOMElement;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Assets\CategoryNavigation\HtmlPage;
use Tests\TestCase;

/**
 * Super-User-only asset category permission matrix on the permission-group
 * create/edit form (ERS Phase 5A).
 */
class GroupAssetCategoryPermissionsUiTest extends TestCase
{
    use BuildsPermissionFixture;

    private const INPUT = SaveGroupAssetCategoryPermissionsAction::INPUT;

    private const MARKER = SaveGroupAssetCategoryPermissionsAction::MARKER;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTree();
    }

    /** @return array<int, array<string, bool>> category id => operation => flag */
    private function stored(Group $group): array
    {
        $stored = [];
        foreach (AssetCategoryPermission::query()->where('group_id', $group->id)->get() as $row) {
            foreach (AssetCategoryAccess::OPERATIONS as $operation) {
                $stored[$row->category_id][$operation] = (bool) $row->{AssetCategoryAccess::column($operation)};
            }
        }
        ksort($stored);

        return $stored;
    }

    private function editPage(Group $group, ?User $user = null): HtmlPage
    {
        return new HtmlPage($this->actingAs($user ?? $this->superUser())->get(route('groups.edit', $group))->assertOk()->getContent());
    }

    private function row(HtmlPage $page, int $id): ?DOMElement
    {
        return $page->first('//tr[@data-acp-node="'.$id.'"]');
    }

    private function checkbox(HtmlPage $page, int $categoryId, string $operation): ?DOMElement
    {
        return $page->first('//input[@name="'.self::INPUT.'['.$categoryId.']['.$operation.']"]');
    }

    private function update(Group $group, array $extra, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->superUser())
            ->from(route('groups.edit', $group))
            ->put(route('groups.update', $group), array_merge(['name' => $group->name, 'notes' => 'n'], $extra));
    }

    // ---------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------

    #[Test]
    public function the_edit_page_renders_the_live_nested_hierarchy(): void
    {
        $page = $this->editPage(Group::factory()->create());

        $rows = array_map(fn (DOMElement $tr) => (int) $tr->getAttribute('data-acp-node'), $page->all('//table[contains(@class,"ers-acp-table")]/tbody/tr'));
        // Tree order (sort_order, then name); empty groups, deleted and
        // non-asset categories are not listed.
        $this->assertSame([
            $this->cat['groupA']->id, $this->cat['branch']->id, $this->cat['leaf1']->id, $this->cat['leaf2']->id, $this->cat['direct']->id,
            $this->cat['groupB']->id, $this->cat['other']->id,
            $this->cat['loose']->id,
        ], $rows);

        // Final categories: four named checkboxes with accessible labels.
        foreach (['leaf1', 'leaf2', 'direct', 'other', 'loose'] as $key) {
            foreach (AssetCategoryAccess::OPERATIONS as $operation) {
                $box = $this->checkbox($page, $this->cat[$key]->id, $operation);
                $this->assertNotNull($box, "{$key} {$operation}");
                $this->assertSame('1', $box->getAttribute('value'));
                $this->assertStringContainsString($this->cat[$key]->name, $box->getAttribute('aria-label'));
            }
        }

        // Navigation groups: bulk toggles only, never submitted.
        foreach (['groupA', 'branch', 'groupB'] as $key) {
            $row = $this->row($page, $this->cat[$key]->id);
            $this->assertStringContainsString('ers-acp-group', $row->getAttribute('class'));
            $this->assertCount(4, $page->all('.//input[contains(@class,"ers-acp-bulk")]', $row), $key);
            $this->assertCount(0, $page->all('.//input[@name]', $row), $key);
            $this->assertNull($this->checkbox($page, $this->cat[$key]->id, 'view'), $key);
        }

        // Nesting: leaf1 sits under groupA and branch.
        $this->assertSame(
            $this->cat['groupA']->id.' '.$this->cat['branch']->id,
            $this->row($page, $this->cat['leaf1']->id)->getAttribute('data-acp-ancestors')
        );
        foreach (['deleted', 'accessory', 'emptyGroup'] as $key) {
            $this->assertNull($this->row($page, $this->cat[$key]->id), $key);
        }

        $this->assertSame('1', $page->first('//input[@name="'.self::MARKER.'"]')->getAttribute('value'));
    }

    #[Test]
    public function the_create_page_shows_an_empty_matrix(): void
    {
        $page = new HtmlPage($this->actingAs($this->superUser())->get(route('groups.create'))->assertOk()->getContent());

        $this->assertNotNull($this->checkbox($page, $this->cat['leaf1']->id, 'view'));
        $this->assertSame(0, $page->count('//input[contains(@class,"ers-acp-cell") and @checked]'));
    }

    #[Test]
    public function saved_grants_are_preselected(): void
    {
        $group = $this->groupWithAssetPermissions();
        $this->grant($group, $this->cat['leaf2'], ['view', 'delete']);

        $page = $this->editPage($group);

        foreach (AssetCategoryAccess::OPERATIONS as $operation) {
            $this->assertSame(in_array($operation, ['view', 'delete'], true), $this->checkbox($page, $this->cat['leaf2']->id, $operation)->hasAttribute('checked'), $operation);
            $this->assertFalse($this->checkbox($page, $this->cat['leaf1']->id, $operation)->hasAttribute('checked'), $operation);
        }
    }

    #[Test]
    public function category_names_are_escaped(): void
    {
        $this->finalCategory('<img src=x onerror=alert(1)> & "q"', $this->cat['groupB']);

        $html = $this->actingAs($this->superUser())->get(route('groups.edit', Group::factory()->create()))->getContent();

        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; &amp; &quot;q&quot;', $html);
    }

    // ---------------------------------------------------------------
    // Saving
    // ---------------------------------------------------------------

    #[Test]
    public function super_admins_save_and_replace_the_matrix(): void
    {
        $group = $this->groupWithAssetPermissions();
        $this->grant($group, $this->cat['loose'], AssetCategoryAccess::OPERATIONS);

        $this->update($group, [
            self::MARKER => '1',
            self::INPUT => [
                $this->cat['leaf1']->id => ['view' => '1', 'update' => '1'],
                $this->cat['other']->id => ['delete' => '1'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect(route('groups.index'));

        // "loose" was omitted, so its grant is gone; only checked flags stored.
        $this->assertSame([
            $this->cat['leaf1']->id => ['view' => true, 'create' => false, 'update' => true, 'delete' => false],
            $this->cat['other']->id => ['view' => false, 'create' => false, 'update' => false, 'delete' => true],
        ], $this->stored($group));

        // Unchecking everything (marker only) removes every grant.
        $this->update($group, [self::MARKER => '1'])->assertSessionHasNoErrors();
        $this->assertSame([], $this->stored($group));
    }

    #[Test]
    public function grants_can_be_set_when_creating_a_group(): void
    {
        $this->actingAs($this->superUser())
            ->post(route('groups.store'), [
                'name' => 'New group '.$this->randomName('g'),
                self::MARKER => '1',
                self::INPUT => [$this->cat['direct']->id => ['create' => '1']],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('groups.index'));

        $group = Group::query()->latest('id')->first();
        $this->assertSame([$this->cat['direct']->id => ['view' => false, 'create' => true, 'update' => false, 'delete' => false]], $this->stored($group));
    }

    #[Test]
    public function without_the_matrix_grants_are_left_unchanged(): void
    {
        $group = $this->groupWithAssetPermissions();
        $this->grant($group, $this->cat['leaf1'], ['view']);
        $before = $this->stored($group);

        $this->update($group, ['name' => 'Renamed '.$this->randomName('g')])->assertSessionHasNoErrors();

        $this->assertSame($before, $this->stored($group));
    }

    public static function forgedPayloads(): array
    {
        return [
            'navigation group id' => [fn ($t) => [$t->cat['groupA']->id => ['view' => '1']]],
            'deleted category' => [fn ($t) => [$t->cat['deleted']->id => ['view' => '1']]],
            'non-asset category' => [fn ($t) => [$t->cat['accessory']->id => ['view' => '1']]],
            'unknown id' => [fn ($t) => [987654 => ['view' => '1']]],
            'non-numeric id' => [fn ($t) => ['abc' => ['view' => '1']]],
            'unknown operation' => [fn ($t) => [$t->cat['leaf1']->id => ['checkout' => '1']]],
            'value other than 1' => [fn ($t) => [$t->cat['leaf1']->id => ['view' => 'on']]],
            'false value' => [fn ($t) => [$t->cat['leaf1']->id => ['view' => '0']]],
            'nested array value' => [fn ($t) => [$t->cat['leaf1']->id => ['view' => ['1']]]],
            'row not an array' => [fn ($t) => [$t->cat['leaf1']->id => '1']],
            'matrix not an array' => [fn ($t) => 'all'],
            'one valid and one forged row' => [fn ($t) => [$t->cat['leaf1']->id => ['view' => '1'], $t->cat['groupB']->id => ['view' => '1']]],
        ];
    }

    #[Test]
    #[DataProvider('forgedPayloads')]
    public function forged_input_changes_nothing(callable $payload): void
    {
        $group = $this->groupWithAssetPermissions();
        $group->name = 'Original '.$this->randomName('g');
        $group->save();
        $this->grant($group, $this->cat['loose'], ['view']);
        $before = $this->stored($group);
        $member = User::factory()->create();

        $this->update($group, [
            'name' => 'Should not be saved',
            'users_to_sync' => (string) $member->id,
            self::MARKER => '1',
            self::INPUT => $payload($this),
        ])
            ->assertRedirect(route('groups.edit', $group))
            ->assertSessionHasErrors([self::INPUT => trans('admin/groups/asset_category_permissions.invalid')]);

        // Nothing at all was written: grants, name and members unchanged.
        $this->assertSame($before, $this->stored($group));
        $this->assertStringStartsWith('Original', $group->fresh()->name);
        $this->assertSame(0, $group->users()->count());
    }

    #[Test]
    public function a_failed_group_save_writes_no_grants(): void
    {
        $existing = Group::factory()->create();
        $group = $this->groupWithAssetPermissions();

        // Duplicate name fails group validation after the matrix passed.
        $this->update($group, [
            'name' => $existing->name,
            self::MARKER => '1',
            self::INPUT => [$this->cat['leaf1']->id => ['view' => '1']],
        ])->assertSessionHasErrors(['name']);

        $this->assertSame([], $this->stored($group));
    }

    #[Test]
    public function invalid_submissions_keep_the_ticked_boxes(): void
    {
        $group = $this->groupWithAssetPermissions();

        $this->update($group, [
            self::MARKER => '1',
            self::INPUT => [$this->cat['leaf2']->id => ['update' => '1'], $this->cat['groupA']->id => ['view' => '1']],
        ])->assertSessionHasErrors([self::INPUT]);

        $page = new HtmlPage($this->actingAs($this->superUser())
            ->withSession(['_old_input' => [self::MARKER => '1', self::INPUT => [$this->cat['leaf2']->id => ['update' => '1']]]])
            ->get(route('groups.edit', $group))->getContent());

        $this->assertTrue($this->checkbox($page, $this->cat['leaf2']->id, 'update')->hasAttribute('checked'));
        $this->assertFalse($this->checkbox($page, $this->cat['leaf2']->id, 'view')->hasAttribute('checked'));
    }

    #[Test]
    public function ordinary_group_permissions_still_save(): void
    {
        $group = Group::factory()->create();
        $this->grant($group, $this->cat['leaf1'], ['view']);

        $this->update($group, [
            'permission' => ['assets.view' => '1', 'assets.edit' => '1', 'invalid.permission' => '1'],
            self::MARKER => '1',
            self::INPUT => [$this->cat['leaf1']->id => ['view' => '1', 'update' => '1']],
        ])->assertSessionHasNoErrors();

        $decoded = (array) $group->fresh()->decodePermissions();
        $this->assertSame(1, $decoded['assets.view']);
        $this->assertSame(1, $decoded['assets.edit']);
        $this->assertArrayNotHasKey('invalid.permission', $decoded);
        $this->assertTrue($this->stored($group)[$this->cat['leaf1']->id]['update']);
    }

    // ---------------------------------------------------------------
    // Super User only
    // ---------------------------------------------------------------

    #[Test]
    public function only_super_users_hold_the_management_ability(): void
    {
        $this->assertTrue(Gate::forUser($this->superUser())->allows(SaveGroupAssetCategoryPermissionsAction::ABILITY));
        $this->assertTrue(Gate::forUser($this->superUserViaGroup())->allows(SaveGroupAssetCategoryPermissionsAction::ABILITY));
        $this->assertFalse(Gate::forUser($this->ordinaryAdmin())->allows(SaveGroupAssetCategoryPermissionsAction::ABILITY));
        $this->assertFalse(Gate::forUser($this->userWithAssetPermissions())->allows(SaveGroupAssetCategoryPermissionsAction::ABILITY));
    }

    #[Test]
    public function non_super_users_can_neither_see_nor_change_the_matrix(): void
    {
        $group = $this->groupWithAssetPermissions();
        $this->grant($group, $this->cat['leaf1'], ['view']);
        $before = $this->stored($group);

        foreach ([$this->ordinaryAdmin(), $this->userWithAssetPermissions()] as $user) {
            $response = $this->actingAs($user)->get(route('groups.edit', $group));
            $this->assertStringNotContainsString(self::INPUT, (string) $response->getContent());
            $this->assertNotSame(200, $response->getStatusCode());

            $this->actingAs($user)->put(route('groups.update', $group), [
                'name' => $group->name,
                self::MARKER => '1',
                self::INPUT => [$this->cat['other']->id => ['delete' => '1']],
            ]);
        }

        $this->assertSame($before, $this->stored($group));
    }

    #[Test]
    public function the_action_refuses_non_super_users_even_if_reached_directly(): void
    {
        $this->actingAs($this->ordinaryAdmin());
        $request = \Illuminate\Http\Request::create('/', 'PUT', [self::MARKER => '1']);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        SaveGroupAssetCategoryPermissionsAction::fromRequest($request);
    }
}
