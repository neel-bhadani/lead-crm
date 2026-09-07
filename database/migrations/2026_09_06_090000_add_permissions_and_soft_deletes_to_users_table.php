<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the user management screen needs from the users table, and nothing else.
 *
 * Two columns, both on `users`. leads, todos and projects are untouched: the
 * whole point of soft deleting a user is that the rows pointing at them stay
 * exactly as they are, so there is nothing to migrate there.
 *
 * `deleted_at` is load-bearing rather than tidy. `todos.assigned_to` is NOT NULL
 * behind a cascading foreign key and `leads.assigned_to` nulls on delete — so a
 * hard DELETE of a user would take their to-dos with it and blank the owner off
 * their leads, destroying the history every dashboard chart reads. The row has
 * to survive; only the login has to stop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             | Null means "this user has never been touched on the permissions
             | tab", which is not the same as "all five off" — it is what makes
             | this migration a no-op for everyone already in the table. Each
             | key resolves through config('crm.permission_defaults') for their
             | role until it is explicitly set. See User::can_().
             */
            $table->json('permissions')->nullable()->after('is_active');

            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['permissions', 'deleted_at']);
        });
    }
};
