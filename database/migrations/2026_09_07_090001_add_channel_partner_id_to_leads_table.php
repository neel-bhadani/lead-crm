<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The link from a lead to the partner it came through.
 *
 * `leads.broker_name` STAYS. It is not renamed, not backfilled and not dropped.
 *
 * Every broker lead already in the table carries a name typed by hand, and
 * those strings are the only record of where that business came from. Matching
 * them to rows in `channel_partners` by name would be a guess made once,
 * silently, against data nobody is going to audit — "Shreeji" and "Shreeji
 * Realty" and "shreeji realtors" are three strings that may or may not be one
 * firm, and a wrong match does not look wrong afterwards: it looks like a
 * broker who brought in business they never brought in, in the one report the
 * client is buying this feature for.
 *
 * So the two columns coexist and the split is by age, not by kind: a lead with
 * `channel_partner_id` set is attributed to a row, a lead without one falls
 * back to whatever text it has always had. Nothing is lost and nothing is
 * invented. An admin who wants an old lead attributed opens it and picks the
 * partner, which is a decision a person made rather than one a migration made.
 *
 * nullOnDelete is belt and braces — ChannelPartnerController only ever soft
 * deletes, so this fires only if a row is removed straight from the database.
 * A lead that lost its partner that way falls back to `broker_name` on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('channel_partner_id')->nullable()->after('broker_name')
                ->constrained('channel_partners')->nullOnDelete();

            // "how many leads did this partner bring, in this window" — the
            // report's intake query and the list page's lead count
            $table->index(['channel_partner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['channel_partner_id']);
            $table->dropIndex(['channel_partner_id', 'created_at']);
            $table->dropColumn('channel_partner_id');
        });
    }
};
