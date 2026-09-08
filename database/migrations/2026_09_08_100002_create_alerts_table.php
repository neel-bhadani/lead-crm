<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A message to a person inside the CRM. Not a follow-up.
     *
     * The difference is the whole reason this table is not `todos`: a follow-up
     * is scheduled work that belongs to a lead and moves it along, an alert is
     * something somebody should know. Reading an alert changes nothing about
     * the lead, and an unread alert is not work anybody is measured on.
     *
     * `created_at` and no `updated_at`. An alert is written once and the only
     * thing that ever changes is `read_at`, which is its own column with its
     * own meaning — a second timestamp that shadowed it would be a worse copy.
     * Alert::UPDATED_AT is null to match.
     *
     * The unique index is not a uniqueness rule, it is the deduplication
     * lookup: "has this user already been told this about this lead recently".
     * See AlertService::raise(), which is the only thing that writes this table
     * and enforces the 24-hour window at creation — filtering duplicates on
     * display would leave the bell counting them.
     */
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('automation_rules')->nullOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('body')->nullable();
            // info | warning | urgent — colour, and nothing else
            $table->string('severity')->default('info');
            $table->dateTime('read_at')->nullable();
            $table->string('action_url')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // the bell: "my unread, newest first"
            $table->index(['user_id', 'read_at', 'created_at']);
            // the dedupe check: "this user, this lead, this type, recently"
            $table->index(['user_id', 'lead_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
