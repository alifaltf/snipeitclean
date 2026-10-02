<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERS Phase 6A: server-side state for the secure asset CSV import.
 *
 * One row per uploaded CSV. URLs use the random `public_id`; the integer
 * primary key is never exposed. The row records the stored file (private
 * disk, random name, SHA-256), the target configuration chosen by the
 * owner and the column mapping by column position. Later phases add row
 * validation (validated_at) and execution (completed_at) on the same row.
 *
 * Referenced ids (users, categories, models, status_labels, companies,
 * locations) are plain unsigned integers without foreign keys: they are
 * re-validated on every request, and imports never block deleting master
 * data. No existing table is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_import_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->unsignedInteger('created_by');

            $table->string('original_filename', 255);
            $table->string('storage_path', 255);
            $table->char('file_sha256', 64);
            $table->unsignedBigInteger('file_size');
            $table->text('headers');
            $table->unsignedInteger('row_count');

            $table->unsignedInteger('category_id')->nullable();
            $table->string('model_source', 16)->nullable();
            $table->unsignedInteger('model_id')->nullable();
            $table->string('status_source', 16)->nullable();
            $table->unsignedInteger('status_id')->nullable();
            $table->string('company_source', 16)->nullable();
            $table->unsignedInteger('company_id')->nullable();
            $table->string('location_source', 16)->nullable();
            $table->unsignedInteger('location_id')->nullable();

            $table->text('mapping')->nullable();
            $table->char('mapping_file_sha256', 64)->nullable();

            $table->string('state', 20)->default('uploaded');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['created_by', 'state']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_import_sessions');
    }
};
