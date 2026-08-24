<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the ERS-specific virtual sidebar grouping column to categories.
     *
     * ers_asset_group is a nullable string. The application only ever
     * writes 'hardware', 'software', or null to it (enforced by
     * App\Models\Category's validation rules and by
     * App\Http\Controllers\CategoriesController), but it is intentionally
     * left as a plain nullable string column rather than a DB-level enum
     * so it stays portable across the DB engines this app supports (MySQL/
     * MariaDB, Postgres, SQLite) without engine-specific enum syntax.
     *
     * Nullable, with no default, so every EXISTING category (of every
     * category_type — asset, accessory, consumable, component, license)
     * keeps ers_asset_group = null after this migration runs. Nothing
     * here classifies, renames, or deletes any existing category — an
     * administrator must explicitly opt an asset category into Hardware
     * or Software via Settings > Categories > Edit.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('ers_asset_group')->nullable()->default(null)->after('category_type');
        });
    }

    /**
     * Reverse the migration. Safe to roll back at any time: this only
     * ever drops the column added above, and never touches category
     * rows, names, or any other column.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('ers_asset_group');
        });
    }
};
