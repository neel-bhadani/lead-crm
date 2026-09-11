<?php

use App\Support\CrmTaxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which desk a new lead lands on, by the stage it is saved at.
 *
 * Nullable, and null means exactly one thing: a terminal stage, where nothing
 * is left to follow up and the lead stays with whoever added it. Every open
 * stage carries a role — LeadStage::booted() fills the default on any write
 * that leaves it empty.
 *
 * Seeded from `crm.stage_owner_roles` here in up(), for the same reason the
 * stages themselves were: a seeder is a thing somebody remembers to run, and a
 * lead added between `migrate` and that moment would be routed by nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_stages', function (Blueprint $table) {
            $table->string('owner_role', 20)->nullable()->after('is_terminal');
        });

        $seeded = (array) config('crm.stage_owner_roles', []);
        $default = (string) config('crm.stage_owner_role_default', 'salesperson');

        foreach (DB::table('lead_stages')->get(['id', 'key', 'is_terminal']) as $stage) {
            DB::table('lead_stages')->where('id', $stage->id)->update([
                'owner_role' => $stage->is_terminal ? null : ($seeded[$stage->key] ?? $default),
            ]);
        }

        // DB::table() fires no model events, so nothing else busts the cache
        CrmTaxonomy::flush();
    }

    public function down(): void
    {
        Schema::table('lead_stages', function (Blueprint $table) {
            $table->dropColumn('owner_role');
        });

        CrmTaxonomy::flush();
    }
};
