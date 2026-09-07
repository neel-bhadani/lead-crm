<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider's own id for a lead that arrived from one — a Meta `leadgen_id`.
 *
 * Nullable, because every lead typed in by hand has none, and unique, because
 * that is the idempotency guarantee: Meta retries a webhook it thinks timed
 * out, and a retry that arrived while the first delivery was still working
 * would otherwise make a second lead. The importer checks this column before
 * inserting AND catches the constraint violation, since two concurrent
 * deliveries can both pass the check.
 *
 * A separate migration rather than an edit to create_leads_table: that file has
 * already run everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn('external_id');
        });
    }
};
