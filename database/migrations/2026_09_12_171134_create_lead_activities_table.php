<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What happened to a lead, one row per fact.
     *
     * Written by LeadActivityRecorder and nothing else, always inside the
     * transaction of the change it describes — a row here means the change
     * committed, and a change that committed has its row.
     *
     * `user_id` is null when no person did it: automation, or a lead that
     * arrived from an integration. The timeline prints that as "Automation",
     * never as whoever wrote the rule.
     *
     * `action` is a LeadActivity constant. `field`, `from_value` and `to_value`
     * are raw stored values (keys and ids, not labels), so renaming a stage or
     * a project does not rewrite history into words nobody chose at the time.
     *
     * No backfill. Leads that existed before this table have no recorded
     * history here, and LeadTimeline synthesises only their creation from the
     * lead row itself.
     */
    public function up(): void
    {
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('field')->nullable();
            $table->text('from_value')->nullable();
            $table->text('to_value')->nullable();
            $table->text('remark')->nullable();
            $table->dateTime('created_at');

            // one lead's history, in the order it was written
            $table->index(['lead_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
    }
};
