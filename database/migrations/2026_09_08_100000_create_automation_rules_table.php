<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One rule: one trigger, any number of ANDed conditions, one or more
     * actions run in order.
     *
     * `trigger` holds the key only — `stage_changed`, `follow_up_overdue` — and
     * `trigger_config` holds whatever that trigger needs to be answerable: the
     * stage for "moved to X", the day count for "overdue by N days". They are
     * two columns rather than one because the key is what the engine indexes
     * and looks rules up by, and a JSON blob cannot be indexed. Nothing else in
     * the row is queryable either, which is the point: conditions and actions
     * are read by the engine after the rule has already been selected.
     *
     * `is_active` defaults to FALSE and that default is load-bearing. A rule is
     * written, read back in plain words, tested against the leads it would
     * match, and only then switched on. See AutomationRuleRequest.
     */
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('trigger');
            $table->json('trigger_config')->nullable();
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('is_active')->default(false);
            $table->dateTime('last_fired_at')->nullable();
            $table->unsignedInteger('fire_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // how the engine finds its candidates: "the active rules on this
            // trigger", which is the only question ever asked of this table
            $table->index(['trigger', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
