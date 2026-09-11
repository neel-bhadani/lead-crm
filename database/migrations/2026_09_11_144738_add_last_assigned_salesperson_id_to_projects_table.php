<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each project's salesperson round robin has got to.
 *
 * A column rather than the cache key it replaces, for two reasons. It is per
 * project, so a lead on one development does not take a turn from another's
 * team. And it lives in the database, so LeadAssignmentService can lock the
 * project row, read this and write it back inside the same transaction that
 * saves the lead — two leads arriving together cannot both read the same value
 * and land on the same person, and a lead that fails to save gives its turn
 * back.
 *
 * Null means nobody has had a turn yet. nullOnDelete, so a hard-deleted user
 * resets the pointer instead of blocking the delete; a pointer at somebody no
 * longer on the list is harmless either way — the next turn is simply the first
 * id above it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('last_assigned_salesperson_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_assigned_salesperson_id');
        });
    }
};
