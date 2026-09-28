<?php

namespace Tests\Feature\AssetModels\CategoryAssignment;

use App\Models\Category;
use App\Rules\AssignableAssetCategory;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Categories\Hierarchy\CreatesCategoryHierarchy;
use Tests\TestCase;

/**
 * The shared "live, final/assignable asset category" rule (ERS Phase 3).
 */
class AssignableAssetCategoryRuleTest extends TestCase
{
    use CreatesCategoryHierarchy;

    private function errorsFor(mixed $value): array
    {
        return Validator::make(['category_id' => $value], ['category_id' => [new AssignableAssetCategory]])
            ->errors()
            ->get('category_id');
    }

    #[Test]
    public function it_accepts_a_live_final_asset_category(): void
    {
        $root = $this->finalCategory('Unclassified');
        $leaf = $this->finalCategory('Laptop', $this->group('Hardware'));

        foreach ([$root->id, (string) $leaf->id] as $value) {
            $this->assertSame([], $this->errorsFor($value));
            $this->assertTrue(AssignableAssetCategory::passes($value));
        }
    }

    #[Test]
    public function it_rejects_a_navigation_group(): void
    {
        $group = $this->group('Hardware');

        $this->assertSame([trans('admin/models/message.category_rule.navigation')], $this->errorsFor($group->id));
        $this->assertSame(AssignableAssetCategory::NAVIGATION, AssignableAssetCategory::problemFor($group->id));
    }

    #[Test]
    public function it_rejects_non_asset_categories(): void
    {
        foreach (['forAccessories', 'forConsumables', 'forComponents', 'forLicenses'] as $state) {
            $category = Category::factory()->{$state}()->create();

            $this->assertSame([trans('admin/models/message.category_rule.not_asset')], $this->errorsFor($category->id), $state);
        }
    }

    #[Test]
    public function it_rejects_a_soft_deleted_category(): void
    {
        $category = $this->finalCategory('Retired');
        $category->delete();

        $this->assertSame([trans('admin/models/message.category_rule.deleted')], $this->errorsFor($category->id));
    }

    #[Test]
    public function it_rejects_a_missing_category(): void
    {
        $this->assertSame([trans('admin/models/message.category_rule.missing')], $this->errorsFor(987654));
    }

    public static function malformedValues(): array
    {
        return [
            'zero' => [0],
            'zero string' => ['0'],
            'negative' => [-4],
            'negative string' => ['-4'],
            'text' => ['laptop'],
            'decimal string' => ['1.5'],
            'float' => [3.0],
            'boolean' => [true],
            'array' => [[1]],
            'leading space' => [' 1'],
        ];
    }

    #[Test]
    #[DataProvider('malformedValues')]
    public function it_rejects_malformed_values(mixed $value): void
    {
        $this->finalCategory('Would match id 1');

        $this->assertSame([trans('admin/models/message.category_rule.invalid')], $this->errorsFor($value));
    }

    #[Test]
    public function the_category_read_takes_a_shared_row_lock(): void
    {
        $query = AssignableAssetCategory::lockingQuery(42)->toBase();
        $mysqlSql = (new MySqlGrammar($query->getConnection()))->compileSelect($query);

        $this->assertStringContainsString('lock in share mode', $mysqlSql);
        $this->assertStringNotContainsString('deleted_at', $mysqlSql, 'soft-deleted rows are read too, to report "deleted"');
    }

    #[Test]
    public function every_problem_has_a_translated_message(): void
    {
        foreach ([AssignableAssetCategory::INVALID, AssignableAssetCategory::MISSING, AssignableAssetCategory::DELETED, AssignableAssetCategory::NOT_ASSET, AssignableAssetCategory::NAVIGATION] as $problem) {
            $key = 'admin/models/message.category_rule.'.$problem;
            $this->assertNotSame($key, trans($key), "missing translation for {$problem}");
        }
    }
}
