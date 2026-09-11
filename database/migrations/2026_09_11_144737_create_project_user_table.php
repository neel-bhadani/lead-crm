<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which salespeople handle which project.
 *
 * The salesperson round robin takes turns only among the active salespeople
 * ticked here for the lead's project — see LeadAssignmentService. A project
 * with nobody ticked still gets its leads assigned, from every active
 * salesperson, and every admin is alerted that it is not set up.
 *
 * Nothing is backfilled. Every project starts empty, which is exactly the case
 * the alert exists for: the admin is told, per project, until they tick
 * somebody, rather than being handed an "everybody on everything" list that
 * looks configured and is not.
 *
 * Both keys cascade. Users and projects both soft delete, so in practice this
 * only fires on a hard delete — and a pivot row pointing at a row that is gone
 * has nothing left to say.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_user', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_user');
    }
};
