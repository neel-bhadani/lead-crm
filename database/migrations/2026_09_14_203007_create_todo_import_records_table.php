<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per to-do `import:legacy` writes, and the idempotency key for it.
 *
 * A lead alone is not enough — one lead can carry eight follow-ups from eight
 * sheet columns. `completed_at` alone is not either — two of those columns can
 * share a date. `source_column` (first_visit, follow_up_3, absorbed_stage,
 * final_stage, ...) is what genuinely never collides: the sheet has exactly
 * one of each per row, so (source_file, source_row, source_column) is unique
 * by construction. A second run finds the row already here and writes
 * nothing for it — same idea as lead_import_records, one level down.
 *
 * No columns added to `todos` to carry this — todos.assigned_to /
 * completed_by are real foreign keys the app reads every day; this is
 * importer bookkeeping and lives beside it instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todo_import_records', function (Blueprint $table) {
            $table->id();

            $table->string('source_file', 50);
            $table->unsignedInteger('source_row');
            $table->string('source_column', 30);

            $table->foreignId('todo_id')->constrained()->cascadeOnDelete();

            // which run wrote it; deliberately no timestamps, see lead_import_records
            $table->string('import_batch', 36);

            $table->unique(['source_file', 'source_row', 'source_column'], 'todo_import_records_source_unique');
            $table->index('todo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todo_import_records');
    }
};
