<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERS Phase 7A: the ordered asset-table columns of a final asset category.
 *
 * One row per (category, column), holding only a stable field key
 * ("standard:<field>" or "custom_field:<id>") and its 1-based position.
 * No labels, category names, custom-field names or asset values are
 * stored. A category without rows uses the safe default layout
 * (App\Services\AssetTableLayout\AssetTableFieldCatalog::DEFAULT_KEYS).
 *
 * Rows are written only by App\Services\AssetTableLayout\AssetTableLayoutService,
 * which replaces a category's complete layout in one transaction, so
 * (category_id, position) can be unique as well as (category_id, field_key).
 *
 * Referenced table: categories.id (App\Models\Category, increments =>
 * unsigned int). snipeit:purge hard-deletes soft-deleted categories, so
 * the foreign key cascades; the application also removes a category's
 * rows when it is deleted or stops being a final asset category, so
 * behaviour does not depend on the database enforcing foreign keys.
 *
 * No existing table is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_category_columns', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('category_id');
            $table->string('field_key', 64);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['category_id', 'field_key'], 'acc_category_field_unique');
            $table->unique(['category_id', 'position'], 'acc_category_position_unique');
            $table->index('category_id', 'acc_category_id_index');

            $table->foreign('category_id', 'acc_category_id_foreign')
                ->references('id')->on('categories')
                ->cascadeOnDelete();
        });
    }

    /**
     * Dropping the table removes every configured layout (categories then
     * use the default layout again). No asset or custom-field data is
     * stored here.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_category_columns');
    }
};
