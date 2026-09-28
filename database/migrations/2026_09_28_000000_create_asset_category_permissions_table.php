<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERS Phase 5A: per-group permissions on final/assignable asset categories.
 *
 * One row per (permission group, asset category) pair, holding four
 * independent booleans. Rows are only ever written for live, final asset
 * categories (App\Actions\Groups\SaveGroupAssetCategoryPermissionsAction);
 * navigation groups never get rows. A missing row means "no access"
 * (default deny).
 *
 * Referenced tables (confirmed against the existing schema):
 *  - permission_groups.id  (App\Models\Group, increments => unsigned int)
 *  - categories.id         (App\Models\Category, increments => unsigned int)
 *
 * Deleting behaviour: Snipe-IT hard-deletes permission groups and
 * snipeit:purge hard-deletes soft-deleted categories, so both foreign keys
 * cascade; a grant can never outlive its group or category row. The
 * application also removes grants explicitly (group deletion, category
 * soft-deletion or conversion), so behaviour does not depend on the
 * database enforcing foreign keys (SQLite test connections do not).
 *
 * No existing tables or hierarchy columns are modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_category_permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('group_id');
            $table->unsignedInteger('category_id');
            $table->boolean('can_view')->default(false);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_update')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->timestamps();

            // One row per group/category; also serves group_id lookups.
            $table->unique(['group_id', 'category_id'], 'acp_group_category_unique');
            // Category-side lookups and cascades.
            $table->index('category_id', 'acp_category_id_index');

            $table->foreign('group_id', 'acp_group_id_foreign')
                ->references('id')->on('permission_groups')
                ->cascadeOnDelete();
            $table->foreign('category_id', 'acp_category_id_foreign')
                ->references('id')->on('categories')
                ->cascadeOnDelete();
        });
    }

    /**
     * Dropping the table removes every asset category grant. Take a
     * database backup first on a system where grants have been configured.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_category_permissions');
    }
};
