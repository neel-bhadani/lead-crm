<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Channel partners: one table, three cases.
 *
 *   an individual broker        type `broker`, parent_id null
 *   a firm the lead came        type `firm`   — a lead points straight at it
 *   through directly
 *   a broker inside a firm      type `broker`, parent_id = that firm's row
 *
 * Self-referencing rather than two tables, because a firm and a broker carry
 * the same six contact columns and the same reporting question is asked of
 * both — "how much business did this row bring". Two tables would mean two
 * foreign keys on `leads` and a union in every report that grouped by either.
 *
 * ONE LEVEL ONLY. `parent_id` may point at a firm and nothing else, which is
 * enforced in ChannelPartnerRequest rather than in the schema — MySQL cannot
 * express "the parent's type column must read firm" in a constraint. The
 * schema's half of it is the nullOnDelete below: a firm hard-deleted straight
 * out of the database leaves its brokers standing as individuals rather than
 * pointing at nothing.
 *
 * No commission columns. The client asked for the attribution, not the payout,
 * and a nullable `commission_pct` nobody fills in is a column that will be
 * reported on wrongly the first time somebody does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // 'firm' | 'broker' — a string, like leads.source and leads.stage,
            // so the labels live in config('crm.channel_partner_types') and
            // adding a third kind is not an ALTER on a live table
            $table->string('type');

            $table->foreignId('parent_id')->nullable()
                ->constrained('channel_partners')->nullOnDelete();

            // mainly for firms: the person at the firm you actually ring
            $table->string('contact_person')->nullable();

            $table->string('phone');
            $table->string('alt_phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // the list page filters on both of these together
            $table->index(['type', 'is_active']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_partners');
    }
};
