<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two additions the Projects screen needs, in a new migration rather than
     * in the original — that one has run everywhere and is not ours to edit.
     *
     * `description` is what an admin writes about the project for other staff:
     * the configurations, the possession date, whatever is worth knowing when
     * a lead asks. Nullable, because every project that already exists has none.
     *
     * `deleted_at` is the important one, and it is a safety fix as much as a
     * feature. `leads.project_id` is a foreign key with cascadeOnDelete, so a
     * hard DELETE on a project takes every lead attached to it — silently,
     * irreversibly, and including all their follow-up history. Nothing in the
     * application should ever be one mis-click away from that.
     *
     * With soft deletes the row stays and the cascade never fires. The Projects
     * screen refuses deletion outright while a project has any leads at all and
     * points the admin at deactivation instead; this column is what makes the
     * remaining case — deleting a project nobody ever filed a lead against —
     * something that can be undone in the database rather than mourned.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->text('description')->nullable()->after('type');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['description', 'deleted_at']);
        });
    }
};
