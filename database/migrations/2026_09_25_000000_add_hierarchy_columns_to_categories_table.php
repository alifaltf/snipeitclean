<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERS Feature 1: nested asset category hierarchy (schema only).
 *
 * Adds an adjacency-list parent pointer plus a "navigation-only" flag to
 * categories. This migration deliberately performs NO data changes:
 * every existing row keeps parent_id = NULL, is_assignable = true and
 * sort_order = 0, which is exactly today's flat behaviour. Nothing is
 * classified automatically and non-asset categories are unaffected.
 *
 * Integrity (parent must be a live asset navigation node, no cycles,
 * max depth, etc.) is enforced in application code, matching the
 * existing locations.parent_id convention. No database foreign key is
 * added, so SQLite needs no table rebuild and snipeit:purge can still
 * hard-delete soft-deleted rows. No CHECK constraints or recursive CTEs
 * are used, so this runs unchanged on SQLite, MariaDB/MySQL and
 * PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedInteger('parent_id')->nullable()->after('id');
            $table->boolean('is_assignable')->default(true)->after('category_type');
            $table->unsignedInteger('sort_order')->default(0)->after('is_assignable');
        });

        Schema::table('categories', function (Blueprint $table) {
            // Children lookups (Category::children()).
            $table->index(['parent_id'], 'categories_parent_id_index');
            // Whole-tree load: WHERE category_type = 'asset' (AssetCategoryTree).
            $table->index(['category_type', 'parent_id'], 'categories_type_parent_index');
        });
    }

    /**
     * Rolling back discards hierarchy structure (parents, navigation
     * flags and ordering). Take a database backup before rolling back
     * on a system that has a configured hierarchy.
     */
    public function down(): void
    {
        // Indexes must be dropped before their columns (required on SQLite).
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('categories_type_parent_index');
            $table->dropIndex('categories_parent_id_index');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['parent_id', 'is_assignable', 'sort_order']);
        });
    }
};
