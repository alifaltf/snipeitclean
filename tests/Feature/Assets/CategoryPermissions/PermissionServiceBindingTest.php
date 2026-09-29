<?php

namespace Tests\Feature\Assets\CategoryPermissions;

use App\Models\Asset;
use App\Services\AssetCategoryPermissionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\Support\LegacyAssetCategoryPermissionService;
use Tests\Support\UsesLegacyAssetCategoryCompatibility;
use Tests\TestCase;

/**
 * ERS Phase 5B1: the upstream-test compatibility resolver exists only in the
 * test suite, is enabled only by an explicit trait, and never reaches the
 * application or the Phase 5B1 security tests.
 */
class PermissionServiceBindingTest extends TestCase
{
    use BuildsViewEnforcementFixture;

    #[Test]
    public function a_freshly_booted_application_resolves_the_real_permission_service(): void
    {
        $app = require base_path('bootstrap/app.php');
        $app->make(Kernel::class)->bootstrap();

        $this->assertSame(AssetCategoryPermissionService::class, get_class($app->make(AssetCategoryPermissionService::class)));
        $this->assertSame(AssetCategoryPermissionService::class, get_class($app->make(HttpKernel::class)->getApplication()->make(AssetCategoryPermissionService::class)));
    }

    #[Test]
    public function tests_without_the_compatibility_trait_use_the_real_service(): void
    {
        $this->assertSame(AssetCategoryPermissionService::class, get_class(app(AssetCategoryPermissionService::class)));
        $this->assertNotContains(UsesLegacyAssetCategoryCompatibility::class, class_uses_recursive($this));
    }

    #[Test]
    public function default_deny_applies_when_compatibility_is_not_enabled(): void
    {
        $this->buildAssets();

        $this->actingAsForApi($this->ordinaryAdmin())
            ->getJson(route('api.assets.index'))
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->actingAs($this->viewer([]));
        $this->assertSame(0, Asset::query()->count());
    }

    #[Test]
    public function application_code_never_refers_to_the_compatibility_mechanism(): void
    {
        $finder = (new Finder)->files()->in([base_path('app'), base_path('bootstrap'), base_path('config'), base_path('routes')])->name('*.php')->exclude('cache');

        foreach ($finder as $file) {
            $contents = $file->getContents();
            foreach (['LegacyAssetCategoryPermissionService', 'UsesLegacyAssetCategoryCompatibility', 'Tests\\Support'] as $needle) {
                $this->assertStringNotContainsString($needle, $contents, $file->getRelativePathname());
            }
        }
    }

    #[Test]
    public function ers_and_phase_5b1_tests_never_enable_compatibility(): void
    {
        $finder = (new Finder)->files()->name('*.php')->in([
            base_path('tests/Feature/Assets/CategoryPermissions'),
            base_path('tests/Feature/Assets/CategoryNavigation'),
            base_path('tests/Feature/AssetModels/CategoryAssignment'),
            base_path('tests/Feature/Categories'),
            base_path('tests/Feature/Groups/AssetCategoryPermissions'),
        ]);

        $this->assertGreaterThan(10, iterator_count($finder));
        foreach ($finder as $file) {
            // These two test the compatibility mode itself.
            if (in_array($file->getFilename(), ['PermissionServiceBindingTest.php', 'LegacyCompatibilityModeTest.php'], true)) {
                continue;
            }
            $this->assertStringNotContainsString('UsesLegacyAssetCategoryCompatibility', $file->getContents(), $file->getRelativePathname());
        }
    }

    #[Test]
    public function the_compatibility_resolver_lives_under_tests_only(): void
    {
        $this->assertStringStartsWith(base_path('tests').DIRECTORY_SEPARATOR, (new \ReflectionClass(LegacyAssetCategoryPermissionService::class))->getFileName());
        $this->assertStringStartsWith(base_path('tests').DIRECTORY_SEPARATOR, (new \ReflectionClass(UsesLegacyAssetCategoryCompatibility::class))->getFileName());
    }
}
