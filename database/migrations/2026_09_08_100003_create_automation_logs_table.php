<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why a rule did what it did. Not optional.
     *
     * Automation that leaves no trace is automation nobody can debug: a lead
     * turns up assigned to somebody unexpected and there is no way to tell
     * which rule did it, or whether a rule ran at all and its action failed.
     * Every action writes a row here, and so does every suppression — a rule
     * held back by loop protection is the single most confusing thing this
     * feature can do, and it has to be visible.
     *
     * `action` is the action key, or one of the engine's own words:
     *   rule_fired   the rule matched and its actions are about to run. This is
     *                also the row the one-hour-per-lead cooldown reads, which
     *                is why it is written before the actions rather than after.
     *   suppressed   the rule matched and was held back; `result` says which
     *                guard stopped it.
     *
     * `result` is success | failed | skipped | fired | loop_guard | cooldown.
     */
    public function up(): void
    {
        Schema::create('automation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->nullable()->constrained('automation_rules')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('result');
            $table->text('error')->nullable();
            $table->dateTime('fired_at');

            // the cooldown lookup: "did this rule fire on this lead in the last
            // hour" — the busiest read on the table, and the one that decides
            // whether two rules can ping-pong a stage
            $table->index(['rule_id', 'lead_id', 'fired_at']);
            // the Activity tab: newest first, and one lead's history
            $table->index('fired_at');
            $table->index(['lead_id', 'fired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_logs');
    }
};
