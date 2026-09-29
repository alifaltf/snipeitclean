<?php

namespace Tests\Feature\Assets\CategoryPermissions;

use App\Models\Asset;
use App\Models\CheckoutAcceptance;
use App\Models\Company;
use App\Models\User;
use App\Notifications\CurrentInventory;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ERS Phase 5B1: strict default deny also applies to assets assigned to the
 * logged-in user. My Assets, the account inventory, inventory printing,
 * inventory email and checkout acceptance all need global assets.view AND a
 * category View grant (Super Admin excepted), and company scope still applies.
 */
class AssetCategoryViewAccountTest extends TestCase
{
    use BuildsViewEnforcementFixture;

    private User $holder;

    private Asset $mine;

    private CheckoutAcceptance $acceptance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTree();
    }

    /** Assign an asset in the 'other' category to $holder, with a pending acceptance. */
    private function assignTo(User $holder, array $attributes = []): void
    {
        $this->holder = $holder;
        $this->mine = $this->assetIn($this->cat['other'], array_merge([
            'asset_tag' => $this->randomName('MINE'),
            'assigned_to' => $holder->id,
            'assigned_type' => User::class,
        ], $attributes));
        $this->acceptance = CheckoutAcceptance::factory()->pending()->create([
            'checkoutable_type' => Asset::class,
            'checkoutable_id' => $this->mine->id,
            'assigned_to_id' => $holder->id,
        ]);
    }

    private function inventoryEmailMentions(User $user, string $text): bool
    {
        Notification::fake();
        $this->actingAs($user)->post(route('profile.email_assets'))->assertRedirect();

        $found = null;
        Notification::assertSentTo($user, CurrentInventory::class, function (CurrentInventory $notification) use ($text, &$found) {
            $found = str_contains((string) $notification->toMail()->render(), $text);

            return true;
        });

        return (bool) $found;
    }

    private function assertHiddenEverywhere(): void
    {
        $tag = $this->mine->asset_tag;
        $user = $this->holder;

        $this->assertStringNotContainsString($tag, $this->actingAs($user)->get(route('view-assets'))->assertOk()->getContent());
        $this->assertStringNotContainsString($tag, $this->actingAs($user)->get(route('account'))->assertOk()->getContent());
        $this->assertStringNotContainsString($tag, $this->actingAs($user)->get(route('profile.print'))->assertOk()->getContent());
        $this->assertFalse($this->inventoryEmailMentions($user, $tag), 'Inventory email listed a hidden asset.');

        $this->assertStringNotContainsString($tag, $this->actingAs($user)->get(route('account.accept'))->getContent());
        $page = $this->actingAs($user)->get(route('account.accept.item', $this->acceptance));
        $this->assertNotSame(200, $page->status());
        $this->assertStringNotContainsString($tag, (string) $page->getContent());

        $this->actingAs($user)->post(route('account.store-acceptance', $this->acceptance), ['asset_acceptance' => 'accepted']);
        $this->assertNull($this->acceptance->fresh()->accepted_at, 'A hidden asset was accepted.');

        $this->assertNotSame(200, $this->actingAs($user)->get(route('hardware.show', $this->mine))->status());
        $this->assertNotSame('success', $this->actingAsForApi($user)->getJson(route('api.assets.show', $this->mine))->json('status'));
    }

    #[Test]
    public function an_assigned_asset_without_category_view_is_hidden_on_every_account_page(): void
    {
        $this->assignTo($this->viewer([['leaf1']]));

        $this->assertHiddenEverywhere();
    }

    #[Test]
    public function an_assigned_asset_is_hidden_from_a_user_with_no_permissions_at_all(): void
    {
        $this->assignTo(User::factory()->create());

        $this->assertHiddenEverywhere();
    }

    #[Test]
    public function a_category_grant_without_global_assets_view_does_not_reveal_the_assigned_asset(): void
    {
        $this->assignTo($this->viewer([['other']], ['reports.view' => '1']));

        $this->assertHiddenEverywhere();
    }

    #[Test]
    public function with_global_assets_view_and_category_view_the_assigned_asset_is_available(): void
    {
        $this->assignTo($this->viewer([['other']]));
        $tag = $this->mine->asset_tag;
        $user = $this->holder;

        $this->assertStringContainsString($tag, $this->actingAs($user)->get(route('view-assets'))->assertOk()->getContent());
        $this->assertStringContainsString($tag, $this->actingAs($user)->get(route('profile.print'))->assertOk()->getContent());
        $this->assertTrue($this->inventoryEmailMentions($user, $tag));
        $this->actingAs($user)->get(route('account.accept.item', $this->acceptance))->assertOk();

        $this->actingAs($user)
            ->post(route('account.store-acceptance', $this->acceptance), ['asset_acceptance' => 'accepted', 'note' => 'ok'])
            ->assertSessionHas('success');
        $this->assertNotNull($this->acceptance->fresh()->accepted_at);
    }

    #[Test]
    public function super_admin_sees_their_own_assigned_asset(): void
    {
        $this->assignTo($this->superUser());

        $this->assertStringContainsString($this->mine->asset_tag, $this->actingAs($this->holder)->get(route('view-assets'))->assertOk()->getContent());
        $this->actingAs($this->holder)->get(route('hardware.show', $this->mine))->assertOk();
        $this->actingAs($this->holder)->get(route('account.accept.item', $this->acceptance))->assertOk();
    }

    #[Test]
    public function company_scope_still_applies_on_account_pages(): void
    {
        [$companyA, $companyB] = Company::factory()->count(2)->create();
        $user = $this->viewer([['other']]);
        $companyA->users()->attach($user->id);
        Company::flushCompanyIdsCache();

        $this->assignTo($user, ['company_id' => $companyB->id]);
        $sameCompany = $this->assetIn($this->cat['other'], [
            'asset_tag' => $this->randomName('SAME'),
            'company_id' => $companyA->id,
            'assigned_to' => $user->id,
            'assigned_type' => User::class,
        ]);

        $this->settings->enableMultipleFullCompanySupport();

        $html = $this->actingAs($user)->get(route('view-assets'))->assertOk()->getContent();
        $this->assertStringContainsString($sameCompany->asset_tag, $html);
        $this->assertStringNotContainsString($this->mine->asset_tag, $html);
        $this->assertNotSame(200, $this->actingAs($user)->get(route('hardware.show', $this->mine))->status());
    }
}
