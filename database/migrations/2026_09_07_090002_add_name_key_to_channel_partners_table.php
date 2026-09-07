<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The database-level guarantee that two partners cannot share a name.
 *
 * Partners are created mid-lead-entry now, by whoever is on the phone, which is
 * the fastest possible way to end up with "Shreeji", "Shreeji Realty" and
 * "shreeji realty" as three brokers. The typeahead and the near-match warning
 * are the two soft defences; this is the hard one, and it is the only one that
 * still holds when a request arrives from something that is not the form.
 *
 * WHY A SEPARATE COLUMN rather than a unique index on `name`:
 *
 *   a plain unique index compares bytes. "Shreeji Realty" and "shreeji realty"
 *   are different bytes, and so are "Shreeji  Realty" and "Shreeji Realty".
 *   MySQL's default collation happens to fold case and SQLite's does not, so an
 *   index on `name` would enforce one rule in production and a different one in
 *   the tests — which is the same as enforcing nothing.
 *
 *   `name_key` is the name reduced to lower-case alphanumeric words separated
 *   by single spaces, written by ChannelPartner::nameKey() on every save. Byte
 *   equality on it means the same thing on every engine.
 *
 * WHY IT IS NULLABLE, and how that excludes soft-deleted rows:
 *
 *   the index has to bind live rows and ignore deleted ones. Adding
 *   `deleted_at` to the index does the opposite of what it looks like — NULL is
 *   distinct from NULL in a unique index, so every live row would compare
 *   unequal to every other and the constraint would never fire.
 *
 *   So the key itself is nulled when a row is soft deleted and rewritten when
 *   it is restored — see ChannelPartner::booted(). A deleted "Shreeji Realty"
 *   holds no key, conflicts with nothing, and leaves the name free to be
 *   entered again; the live ones are the only rows the index can see.
 *
 * The key is deliberately NOT suffix-stripped. "Shreeji Realty" and "Shreeji
 * Estate" are two different keys and the database lets both exist, because they
 * may well be two different firms. Deciding they are probably the same one is
 * the near-match WARNING's job — see ChannelPartner::similarityKey() — and a
 * guess belongs in a prompt a person can overrule, never in a constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_partners', function (Blueprint $table) {
            // 191, not 150: leaves the composite index comfortably inside
            // InnoDB's key length whatever collation the table is created with
            $table->string('name_key', 191)->nullable()->after('name');

            $table->unique(['name_key', 'type'], 'channel_partners_name_key_type_unique');
        });

        // backfill anything that already exists — the column is new, so every
        // row is sitting on a null key that would let a duplicate straight in
        foreach (\App\Models\ChannelPartner::withTrashed()->get(['id', 'name', 'deleted_at']) as $partner) {
            \App\Models\ChannelPartner::withTrashed()
                ->whereKey($partner->id)
                ->update([
                    'name_key' => $partner->deleted_at
                        ? null
                        : \App\Models\ChannelPartner::nameKey($partner->name),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('channel_partners', function (Blueprint $table) {
            $table->dropUnique('channel_partners_name_key_type_unique');
            $table->dropColumn('name_key');
        });
    }
};
