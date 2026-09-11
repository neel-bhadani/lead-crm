<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * The rules every form that writes a person's own account columns shares.
 *
 * Three forms write to `users` — the admin's Users page, the sign-up tab and
 * the profile page — and the two things here must mean exactly the same on all
 * of them. A second copy of "which roles may be handed out" is the copy that
 * gets `admin` added to it by mistake; a second copy of the uniqueness check
 * is the one that forgets soft-deleted rows and 500s on the index instead.
 */
trait ValidatesAccountFields
{
    /**
     * Telecaller or salesperson, never admin.
     *
     * The single definition of that rule. UserRequest applies it to what an
     * admin may create; SignupRequest applies it to what a stranger may ask
     * for. An admin is made in the seeder or the database and nowhere else.
     */
    protected function staffRoleRule(): In
    {
        return Rule::in(config('crm.staff_roles'));
    }

    /**
     * Unique against every row the index sees, including trashed ones, with a
     * message that says which it hit.
     *
     * Both `email` and `mobile_number` are unique indexes on the table, and the
     * table knows nothing about soft deletes — a deleted user still holds their
     * email and their number. So this queries withTrashed() and agrees with the
     * index, rather than passing validation and failing on the INSERT.
     *
     * Pass the same sentence twice where the person asking should not learn
     * that a deleted account exists.
     */
    protected function uniqueAmongUsers(string $column, ?User $ignore, string $trashed, string $taken): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($column, $ignore, $trashed, $taken) {
            $clash = User::withTrashed()
                ->where($column, $value)
                ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore->id))
                ->first();

            if ($clash) {
                $fail($clash->trashed() ? $trashed : $taken);
            }
        };
    }
}
