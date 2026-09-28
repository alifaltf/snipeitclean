<?php

namespace Tests\Feature\Assets\CategoryNavigation;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The navigation and filter code must not name or number specific
 * categories. Behavioural tests already use random names; this guards the
 * source against the approved ERS hierarchy names being wired in.
 */
class NoHardcodedCategoriesTest extends TestCase
{
    private const PHASE4_SOURCES = [
        'app/Services/AssetCategorySelection.php',
        'app/Services/AssetCategoryNavigation.php',
        'app/View/Composers/SidebarComposer.php',
        'app/Http/Controllers/Assets/AssetsController.php',
        'app/Http/Controllers/Api/AssetsController.php',
        'app/Providers/BreadcrumbsServiceProvider.php',
        'resources/views/partials/asset-category-nav.blade.php',
        'resources/views/partials/asset-category-nav-items.blade.php',
        'resources/views/hardware/index.blade.php',
    ];

    /** @return list<string> category names from the "Initial structure" list in the requirements */
    private function ersCategoryNames(): array
    {
        $doc = file_get_contents(base_path('docs/ERS_REQUIREMENTS.md'));
        $this->assertNotFalse($doc);

        $section = substr($doc, strpos($doc, 'Initial structure:'));
        $section = substr($section, 0, strpos($section, 'Parent levels are navigation groups'));
        preg_match_all('/^\s*-\s+(.+?)\s*$/m', $section, $matches);

        return $matches[1];
    }

    #[Test]
    public function phase_four_sources_do_not_name_ers_categories(): void
    {
        $names = $this->ersCategoryNames();
        $this->assertContains('Laptop', $names, 'sanity: the requirements list was parsed');

        foreach (self::PHASE4_SOURCES as $path) {
            $source = file_get_contents(base_path($path));
            foreach ($names as $name) {
                $this->assertDoesNotMatchRegularExpression('/\b'.preg_quote($name, '/').'\b/', $source, "{$path} mentions \"{$name}\"");
            }
        }
    }

    #[Test]
    public function phase_four_sources_do_not_pin_category_ids(): void
    {
        foreach (self::PHASE4_SOURCES as $path) {
            $source = file_get_contents(base_path($path));

            $this->assertDoesNotMatchRegularExpression('/asset_category\s*=\s*\d/', $source, $path);
            $this->assertDoesNotMatchRegularExpression("/['\"]asset_category['\"]\s*=>\s*\d/", $source, $path);
            $this->assertDoesNotMatchRegularExpression('/whereIn\([^)]*category_id[^)]*\[\s*\d/', $source, $path);
        }
    }
}
