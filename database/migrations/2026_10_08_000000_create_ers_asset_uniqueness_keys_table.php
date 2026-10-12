<?php

use App\Services\AssetUniqueness\AssetUniquenessSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERS: the ledger of database uniqueness keys ERS has created or is
 * changing (see App\Services\AssetUniqueness\AssetUniquenessSchema).
 *
 * A row is written BEFORE any schema change and removed after a key is
 * dropped, so every ers_live_* object on the assets table has a row, and a
 * row still marked "activating" or "deactivating" shows a change that was
 * interrupted (php artisan ers:asset-uniqueness --repair finishes it).
 * Objects without a row are not ERS-owned and are never touched.
 *
 * New ERS-owned table only; no existing table is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ers_asset_uniqueness_keys')) {
            return;
        }

        Schema::create('ers_asset_uniqueness_keys', function (Blueprint $table) {
            $table->increments('id');
            $table->string('key', 64)->unique();
            $table->string('source_column', 191);
            $table->string('column_name', 64);
            $table->string('index_name', 64);
            $table->string('status', 16);
            $table->text('definition')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ers_asset_uniqueness_keys');
        AssetUniquenessSchema::forgetReadiness();
    }
};
