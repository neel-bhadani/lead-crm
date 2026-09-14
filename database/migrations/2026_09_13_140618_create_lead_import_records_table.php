<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_import_records', function (Blueprint $table) {
            $table->id();

            /*
             | Where the row came from. Unique together, and that unique key is
             | the whole of `import:legacy`'s idempotency: a second run finds
             | the row already recorded and writes nothing for it.
             */
            $table->string('source_file', 50);
            $table->unsignedInteger('source_row');

            // the lead this row became, or the lead it was absorbed into
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();

            // created | absorbed
            $table->string('outcome', 10);

            $table->string('duplicate_group')->nullable();
            $table->unsignedSmallInteger('duplicate_rank')->nullable();

            /*
             | Set on an open lead imported with no pending follow-up. A snapshot
             | of the import, not a live state: `import:legacy --awaiting` asks
             | the live question, which shrinks as the admin schedules them.
             */
            $table->boolean('awaiting_follow_up')->default(false);

            // how many todos the import wrote for this lead; --fresh checks it
            $table->unsignedInteger('imported_todo_count')->default(0);

            $table->json('filled');
            $table->json('flags');

            // the source row in full — nothing that was in the sheet is lost
            $table->json('legacy');

            // which run wrote it; deliberately no timestamps, see ImportLegacy
            $table->string('import_batch', 36);

            $table->unique(['source_file', 'source_row']);
            $table->index('lead_id');
            $table->index('awaiting_follow_up');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_import_records');
    }
};
