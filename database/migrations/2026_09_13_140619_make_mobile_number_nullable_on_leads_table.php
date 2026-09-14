<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     | For the legacy leads that arrived with no number. They cannot be stored
     | as '' — unique(mobile_number, project_id) would refuse the second one in
     | the same project — and a made-up number would be dialled.
     |
     | NULL is not equal to NULL in a unique index, so any number of them fit.
     | LeadRequest still requires a mobile on every save, so a lead with none
     | cannot be edited until somebody enters one.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('mobile_number')->nullable()->change();
        });
    }

    /**
     * Refuses while any lead has no number, rather than inventing one.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('mobile_number')->nullable(false)->change();
        });
    }
};
