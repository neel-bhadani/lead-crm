<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an admin has said yes to this account yet.
 *
 * pending | approved | rejected. Only the sign-up page ever writes `pending`;
 * everything else — the seeder, the Users page, every row already in the table
 * — is an account an admin made on purpose, so the column defaults to
 * `approved` and this migration changes nothing about who can sign in today.
 *
 * A second column rather than a third value of `is_active`, because the two
 * answer different questions. `is_active` is "may this person sign in right
 * now" and an admin flips it both ways for as long as somebody works here.
 * `approval_status` is "did we ever agree to have them", it is decided once,
 * and it is what lets the login page tell a person who has just signed up that
 * they are waiting rather than that they mistyped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('approval_status', 20)->default('approved')->after('is_active');

            // the Users page's Pending filter, and the pending-first sort
            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['approval_status']);
            $table->dropColumn('approval_status');
        });
    }
};
