<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `leads.assigned_role` is the role of the person in `assigned_to`, and nothing
 * else.
 *
 * Two bugs wrote it otherwise. An admin-created lead was stamped `telecaller`
 * even when no telecaller was active and the lead fell back onto the admin
 * (QA-REPORT MIN-13); and changing a user's role left their leads claiming the
 * old one (QA-REPORT-2 MIN-2). Both writers are fixed; this puts the rows they
 * already wrote back in step, deleted leads included, so a restore cannot bring
 * a mismatch back.
 *
 * A lead with no owner gets no role — the subquery finds no user and yields
 * null. There is no down(): the values being replaced were wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('leads')->update([
            'assigned_role' => DB::raw('(SELECT users.role FROM users WHERE users.id = leads.assigned_to)'),
        ]);
    }

    public function down(): void
    {
        //
    }
};
