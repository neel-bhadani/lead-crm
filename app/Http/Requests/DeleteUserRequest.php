<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HandsOverWork;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Deleting a staff member — which is a soft delete, always.
 *
 * `todos.assigned_to` is NOT NULL behind a cascading foreign key and
 * `leads.assigned_to` nulls on delete, so a real DELETE here would take the
 * user's to-dos with them and blank the owner off their leads. Every
 * per-person chart on the dashboard reads those columns. The row stays; the
 * login stops.
 */
class DeleteUserRequest extends FormRequest
{
    use HandsOverWork;

    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return $this->handoverRules();
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateHandover($validator);

        $validator->after(function ($v) {
            $user = $this->route('user');

            /*
             | Last-admin first, and the order is the whole point.
             |
             | `role:admin` only lets an active admin this far, so the target
             | can only BE the last active admin when the target is the person
             | asking — which means a self-check placed first would answer
             | every one of these with "you cannot delete your own account" and
             | this rule would never once fire. Both sentences are true; only
             | one of them tells the admin what to do about it.
             */
            if ($user->isAdmin() && ! User::active()->where('role', 'admin')->where('id', '!=', $user->id)->exists()) {
                $v->errors()->add('user', 'This is the last active admin. Promote somebody else first.');

                return;
            }

            // an admin deleting themselves is logged out of an application
            // they are the only one who can let them back into
            if ((int) $user->id === (int) $this->user()->id) {
                $v->errors()->add('user', 'You cannot delete your own account.');
            }
        });
    }

    /** Deleting always strands whatever they hold, so this is unconditional. */
    protected function departingUser(): ?User
    {
        return $this->route('user');
    }
}
