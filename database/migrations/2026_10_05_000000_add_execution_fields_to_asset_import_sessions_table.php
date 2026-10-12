<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERS Phase 6C: what an import execution produced.
 *
 * The Phase 6A table already records the owner (created_by), the state and
 * completed_at. Execution additionally needs to keep, after the CSV file is
 * gone, which assets it created (for the final result page) and, after a
 * rolled-back attempt, safe diagnostics of why it failed (row number, field,
 * message; never cell values). None of the existing columns can hold these.
 *
 * - imported_count:       number of assets created by the completed import.
 * - imported_asset_ids:   JSON list of the created asset ids (ids only).
 * - execution_errors:     JSON list of safe diagnostics of the last failed
 *                         execution, cleared when an execution completes.
 * - execution_failed_at:  when the last execution was rolled back.
 * - validation_nonce:     random value written by every successful
 *                         validation and cleared by every change that
 *                         invalidates it (target, mapping, failed
 *                         validation, failed or completed execution). The
 *                         import confirmation is bound to it, so a
 *                         confirmation can only ever authorise the exact
 *                         validation the user reviewed, even when two
 *                         validations happen within the same second.
 *
 * Only adds nullable columns to the ERS-owned table; no existing table or
 * column is changed. down() drops exactly these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_import_sessions', function (Blueprint $table) {
            $table->unsignedInteger('imported_count')->nullable()->after('completed_at');
            $table->mediumText('imported_asset_ids')->nullable()->after('imported_count');
            $table->mediumText('execution_errors')->nullable()->after('imported_asset_ids');
            $table->timestamp('execution_failed_at')->nullable()->after('execution_errors');
            $table->char('validation_nonce', 64)->nullable()->after('validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('asset_import_sessions', function (Blueprint $table) {
            $table->dropColumn(['imported_count', 'imported_asset_ids', 'execution_errors', 'execution_failed_at', 'validation_nonce']);
        });
    }
};
