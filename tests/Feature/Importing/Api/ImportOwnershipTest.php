<?php

namespace Tests\Feature\Importing\Api;

use App\Models\Import;
use App\Models\Location;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Importing\CleansUpImportFiles;
use Tests\Support\Importing\LocationsImportFileBuilder;
use Tests\TestCase;

/**
 * The import API endpoints used to expose every uploaded import to any user
 * holding the flat `import` permission: the index disclosed the first CSV row
 * of each foreign upload, and the process endpoint would read another user's
 * file from disk and create records from it under the caller's identity.
 * These tests pin the owner-only scope on both endpoints, with a superuser
 * escape hatch for admin-tier maintenance.
 *
 * ERS Phase 6A: the native importer is now Super-Admin-only, so a
 * non-Super-User is refused outright (403) instead of being filtered to
 * their own imports.
 */
class ImportOwnershipTest extends TestCase
{
    use CleansUpImportFiles;

    #[Test]
    public function index_only_returns_the_callers_own_imports_for_non_superuser(): void
    {
        $me = User::factory()->canImport()->create();
        Import::factory()->locations()->create(['created_by' => $me->id]);
        Import::factory()->locations()->create(['created_by' => User::factory()->canImport()->create()->id]);

        $this->actingAsForApi($me);

        $this->getJson(route('api.imports.index'))->assertForbidden();
    }

    #[Test]
    public function index_returns_all_imports_for_superuser(): void
    {
        $mine = Import::factory()->locations()->create(['created_by' => ($me = User::factory()->superuser()->create())->id]);
        $theirs = Import::factory()->locations()->create(['created_by' => User::factory()->canImport()->create()->id]);

        $this->actingAsForApi($me);

        $response = $this->getJson(route('api.imports.index'))->assertOk()->json();
        $ids = array_column($response['rows'] ?? $response, 'id');

        $this->assertContains($mine->id, $ids);
        $this->assertContains($theirs->id, $ids);
    }

    #[Test]
    public function process_refuses_and_does_not_read_another_users_file(): void
    {
        $victim = User::factory()->canImport()->create();
        $attacker = User::factory()->canImport()->create();

        $importFileBuilder = LocationsImportFileBuilder::new();
        $row = $importFileBuilder->firstRow();
        $import = Import::factory()->locations()->create([
            'file_path' => $importFileBuilder->saveToImportsDirectory(),
            'created_by' => $victim->id,
        ]);

        $this->actingAsForApi($attacker);

        $this->postJson(
            route('api.imports.importFile', ['import' => $import->id]),
            ['import-type' => 'location']
        )
            ->assertForbidden();

        // The victim's rows must NOT have been imported under the attacker.
        $this->assertDatabaseMissing((new Location)->getTable(), ['name' => $row['name']]);
    }

    #[Test]
    public function process_works_for_the_owner(): void
    {
        // ERS Phase 6A: owning an import is no longer enough; a
        // non-Super-User owner is refused and nothing is imported.
        $owner = User::factory()->canImport()->create();

        $importFileBuilder = LocationsImportFileBuilder::new();
        $row = $importFileBuilder->firstRow();
        $import = Import::factory()->locations()->create([
            'file_path' => $importFileBuilder->saveToImportsDirectory(),
            'created_by' => $owner->id,
        ]);

        $this->actingAsForApi($owner);

        $this->postJson(
            route('api.imports.importFile', ['import' => $import->id]),
            ['import-type' => 'location']
        )->assertForbidden();

        $this->assertDatabaseMissing((new Location)->getTable(), ['name' => $row['name']]);
    }

    #[Test]
    public function process_works_for_a_superuser_on_any_import(): void
    {
        $victim = User::factory()->canImport()->create();
        $superuser = User::factory()->superuser()->create();

        $importFileBuilder = LocationsImportFileBuilder::new();
        $row = $importFileBuilder->firstRow();
        $import = Import::factory()->locations()->create([
            'file_path' => $importFileBuilder->saveToImportsDirectory(),
            'created_by' => $victim->id,
        ]);

        $this->actingAsForApi($superuser);

        $this->postJson(
            route('api.imports.importFile', ['import' => $import->id]),
            ['import-type' => 'location']
        )->assertOk();

        $this->assertDatabaseHas((new Location)->getTable(), ['name' => $row['name']]);
    }
}
