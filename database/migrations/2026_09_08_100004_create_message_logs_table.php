<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What was sent to whom, and what it actually said.
     *
     * `body` is the rendered text, not the template id — a template is edited,
     * and a log that pointed at the current wording would rewrite history every
     * time somebody fixed a typo. `template_id` is kept beside it for grouping
     * and goes null if the template is deleted; the body outlives it.
     *
     * `to_number` is stored for the same reason: a lead's mobile can be
     * corrected after the fact, and "we messaged the wrong number" is precisely
     * the question this table has to be able to answer.
     *
     * `mode` is click | api, and the distinction is honest rather than
     * cosmetic. Click-to-send opens wa.me with the text pre-filled; nothing can
     * observe whether the user then pressed send, so that path reaches
     * `opened` and stops there. Only the API path may write `sent`.
     *
     * `status` is queued | opened | sent | failed | cancelled.
     */
    public function up(): void
    {
        Schema::create('message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('message_templates')->nullOnDelete();
            // who sent it, or null while it is still sitting in the queue
            // waiting for somebody to pick it up
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // which rule queued it, when a rule did; null for a hand-sent one
            $table->foreignId('rule_id')->nullable()->constrained('automation_rules')->nullOnDelete();
            $table->string('mode')->default('click');
            $table->string('to_number')->nullable();
            $table->text('body');
            $table->string('status')->default('queued');
            $table->text('error')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            // the Queue tab: everything still waiting, oldest first
            $table->index(['status', 'created_at']);
            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_logs');
    }
};
