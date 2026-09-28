<?php

namespace Tests\Feature\AssetModels\CategoryAssignment;

use App\Actions\AssetModels\BulkUpdateAssetModelsAction;
use App\Actions\Categories\SaveCategoryHierarchyAction;
use App\Models\AssetModel;
use App\Rules\AssignableAssetCategory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Categories\Hierarchy\CreatesCategoryHierarchy;
use Tests\TestCase;

/**
 * Regression tests for the Phase 3 locking strategy.
 *
 * SQLite cannot run two transactions concurrently inside one test process,
 * so true interleavings are not simulated. Instead these tests pin down
 * the properties the strategy relies on:
 *  1. the lock clauses compile to real row locks on MariaDB/MySQL;
 *  2. every model-category write reads (locks) the category row inside the
 *     same transaction as, and before, the models write;
 *  3. the hierarchy action takes its category row locks before it reads
 *     models (same category -> models order, so no deadlock cycle).
 */
class CategoryAssignmentLockingTest extends TestCase
{
    use CreatesCategoryHierarchy;

    /** @var list<array{sql: string, level: int}> */
    private array $queries = [];

    private function record(): void
    {
        $this->queries = [];
        DB::listen(function (QueryExecuted $query) {
            $this->queries[] = ['sql' => strtolower($query->sql), 'level' => DB::transactionLevel()];
        });
    }

    private function indexOf(string $needle, int $from = 0): int
    {
        foreach (array_slice($this->queries, $from, preserve_keys: true) as $i => $query) {
            if (str_contains($query['sql'], $needle)) {
                return $i;
            }
        }
        $this->fail("No query containing [{$needle}]:\n".implode("\n", array_column($this->queries, 'sql')));
    }

    private function mysql(\Illuminate\Database\Query\Builder $query): string
    {
        return strtolower((new MySqlGrammar($query->getConnection()))->compileSelect($query));
    }

    #[Test]
    public function lock_clauses_compile_to_row_locks_on_mysql(): void
    {
        $this->assertStringContainsString('lock in share mode', $this->mysql(AssignableAssetCategory::lockingQuery(1)->toBase()));

        $count = SaveCategoryHierarchyAction::modelReferenceQuery(1)->toBase();
        $count->aggregate = ['function' => 'count', 'columns' => ['*']];
        $this->assertStringContainsString('lock in share mode', $this->mysql($count));
    }

    #[Test]
    public function a_model_save_locks_its_category_inside_the_write_transaction(): void
    {
        $laptop = $this->finalCategory('Laptop');
        $model = AssetModel::factory()->make(['category_id' => $laptop->id]);
        $base = DB::transactionLevel();

        $this->record();
        $this->assertTrue($model->save());

        $categoryRead = $this->indexOf('select "id", "category_type", "is_assignable", "deleted_at" from "categories"');
        $insert = $this->indexOf('insert into "models"', $categoryRead);
        $this->assertSame($base + 1, $this->queries[$categoryRead]['level']);
        $this->assertSame($base + 1, $this->queries[$insert]['level']);
    }

    #[Test]
    public function a_model_restore_locks_its_category_inside_the_write_transaction(): void
    {
        $laptop = $this->finalCategory('Laptop');
        $model = AssetModel::factory()->create(['category_id' => $laptop->id]);
        $model->delete();
        $base = DB::transactionLevel();

        $this->record();
        $this->assertTrue($model->restore());

        // The last category check before the write happens in the save transaction.
        $update = $this->indexOf('update "models"');
        $reads = array_keys(array_filter(
            array_slice($this->queries, 0, $update, preserve_keys: true),
            fn ($q) => str_contains($q['sql'], 'from "categories"')
        ));
        $this->assertNotEmpty($reads);
        $this->assertSame($base + 1, $this->queries[end($reads)]['level']);
        $this->assertSame($base + 1, $this->queries[$update]['level']);
    }

    #[Test]
    public function the_bulk_update_validates_before_writing_in_one_transaction(): void
    {
        $laptop = $this->finalCategory('Laptop');
        $desktop = $this->finalCategory('Desktop');
        $model = AssetModel::factory()->create(['category_id' => $laptop->id]);
        $base = DB::transactionLevel();

        $this->record();
        BulkUpdateAssetModelsAction::run([$model->id], ['category_id' => $desktop->id]);

        $categoryRead = $this->indexOf('from "categories"');
        $update = $this->indexOf('update "models"', $categoryRead);
        $this->assertSame($base + 1, $this->queries[$categoryRead]['level']);
        $this->assertSame($base + 1, $this->queries[$update]['level']);
    }

    #[Test]
    public function a_failed_bulk_validation_issues_no_update_statement(): void
    {
        $model = AssetModel::factory()->create(['category_id' => $this->finalCategory('Laptop')->id]);
        $group = $this->group('Hardware');

        $this->record();
        try {
            BulkUpdateAssetModelsAction::run([$model->id], ['category_id' => $group->id, 'notes' => 'x']);
            $this->fail('Expected a validation error');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        $this->assertEmpty(array_filter($this->queries, fn ($q) => str_starts_with($q['sql'], 'update')));
    }

    #[Test]
    public function the_hierarchy_action_locks_category_rows_before_reading_models(): void
    {
        $this->actingAs($this->superUser());
        $category = $this->finalCategory('Convert me');
        $base = DB::transactionLevel();

        $this->record();
        SaveCategoryHierarchyAction::run($category, ['is_assignable' => '0']);

        $firstInTransaction = array_key_first(array_filter($this->queries, fn ($q) => $q['level'] === $base + 1));
        $this->assertStringContainsString('from "categories" where "categories"."category_type" = ?', $this->queries[$firstInTransaction]['sql']);

        $modelRead = $this->indexOf('from "models"');
        $this->assertGreaterThan($firstInTransaction, $modelRead);
        $this->assertSame($base + 1, $this->queries[$modelRead]['level']);
    }
}
