<?php

namespace Tests\Feature\Assets\CategoryPermissions;

use App\Models\Asset;
use App\Models\AssetCategoryPermission;
use App\Models\User;
use App\Services\AssetCategoryPermissionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LegacyAssetCategoryPermissionService;
use Tests\Support\UsesLegacyAssetCategoryCompatibility;
use Tests\TestCase;

/**
 * ERS Phase 5B1: what the opt-in upstream-test compatibility mode does and,
 * more importantly, what it does NOT do. It supplies category grants only;
 * the global assets.view upper bound, Super User handling and company
 * scoping are the real ones.
 */
class LegacyCompatibilityModeTest extends TestCase
{
    use BuildsViewEnforcementFixture;
    use UsesLegacyAssetCategoryCompatibility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAssets();
    }

    #[Test]
    public function the_trait_binds_the_test_resolver_for_this_test_only(): void
    {
        $this->assertInstanceOf(LegacyAssetCategoryPermissionService::class, app(AssetCategoryPermissionService::class));
    }

    #[Test]
    public function it_grants_categories_but_never_bypasses_global_assets_view(): void
    {
        $checkoutOnly = User::factory()->checkoutAssets()->checkinAssets()->editAssets()->create();
        $this->actingAs($checkoutOnly);
        $this->assertSame(0, Asset::query()->count());

        $viewer = User::factory()->viewAssets()->create();
        $this->actingAs($viewer);
        $this->assertSame(5, Asset::query()->count());

        // Grants are simulated in memory; nothing is written.
        $this->assertSame(0, AssetCategoryPermission::query()->count());
    }

    #[Test]
    public function it_only_authorises_final_categories(): void
    {
        $access = app(AssetCategoryPermissionService::class)->forUser(User::factory()->viewAssets()->create());

        $this->assertEqualsCanonicalizing($this->finalIds(), $access->categoryIds('view'));
    }
}
