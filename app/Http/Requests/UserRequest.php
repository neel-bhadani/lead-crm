<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HandsOverWork;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add and edit staff. One class for both, the way LeadRequest serves both ends
 * of the lead form — route model binding is what tells them apart.
 */
class UserRequest extends FormRequest
{
    use HandsOverWork;

    /**
     * The route is already behind `role:admin`. This is the second lock on the
     * same door: a route added to that group later without the middleware, or
     * moved out of it by accident, still cannot reach here.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],

            /*
             | Both of these are unique indexes on the table, and the table
             | knows nothing about soft deletes — a deleted user still holds
             | their email and their number. Rule::unique() queries the table
             | directly, so it agrees with the index; what it cannot do is
             | explain itself. The closures below say which kind of row is in
             | the way, because "email has already been taken" against an
             | address nobody can see is the report that turns into a support
             | call.
             */
            'email' => [
                'required', 'email', 'max:150',
                $this->uniqueAmongUsers('email', $user,
                    'That email belongs to a deleted user. Restore them instead of adding a new account.',
                    'That email is already in use.'),
            ],

            'mobile_number' => [
                'required', 'digits:10',
                $this->uniqueAmongUsers('mobile_number', $user,
                    'That mobile number belongs to a deleted user. Restore them instead of adding a new account.',
                    'That mobile number is already in use.'),
            ],

            /*
             | Telecaller or salesperson, never admin. An admin is made in the
             | seeder or the database; letting this screen mint one would mean
             | a single compromised admin session could quietly install a
             | second permanent account. The list lives in config so the rule
             | and the dropdown cannot disagree.
             |
             | An existing admin editing their own name still passes: the rule
             | is skipped when the role is not being changed, or an admin could
             | never be edited at all.
             */
            'role' => [
                'required',
                $user?->isAdmin() && $this->input('role') === 'admin'
                    ? Rule::in(['admin'])
                    : Rule::in(config('crm.staff_roles')),
            ],

            /*
             | Required when adding, optional when editing — blank means
             | unchanged, which is what an admin fixing a typo in a surname
             | expects. `confirmed` pairs it with password_confirmation.
             |
             | Nothing hashes it here or in the controller. The model's
             | `hashed` cast is the only thing that does, and calling
             | Hash::make() on the way in would hash it twice and lock the
             | user out of an account that looks fine.
             */
            'password' => [
                $this->isCreating() ? 'required' : 'nullable',
                'string', 'min:8', 'confirmed',
            ],

            'is_active' => ['boolean'],

            /*
             | The permissions tab. A key that is not in config is dropped
             | rather than rejected — the front end sends the whole set it
             | knows about, and a stale browser tab holding a key that has
             | since been removed should not fail the save.
             */
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['boolean'],
        ] + $this->handoverRules();
    }

    /**
     * Unique against every row the index sees, including trashed ones, with a
     * message that says which it hit.
     */
    private function uniqueAmongUsers(string $column, ?User $ignore, string $trashed, string $taken): \Closure
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

    public function withValidator(Validator $validator): void
    {
        $this->validateHandover($validator);

        $validator->after(function ($v) {
            $user = $this->route('user');

            if (! $user || $this->boolean('is_active')) {
                return;   // adding, or not switching anybody off
            }

            /*
             | The two accounts that must never be switched off. Both are
             | recoverable from the database, but only by somebody who can
             | reach it — and an admin who locks themselves out of their own
             | CRM at 6 PM on a Friday cannot.
             |
             | Last-admin first, for the reason DeleteUserRequest spells out:
             | only an active admin gets this far, so the target can only be
             | the last active admin when it is the person asking, and a
             | self-check placed first would shadow this one entirely.
             */
            if ($user->isAdmin() && $this->isLastActiveAdmin($user)) {
                $v->errors()->add('is_active', 'This is the last active admin. Promote somebody else first.');

                return;
            }

            if ((int) $user->id === (int) $this->user()->id) {
                $v->errors()->add('is_active', 'You cannot deactivate your own account.');
            }
        });
    }

    private function isLastActiveAdmin(User $user): bool
    {
        return ! User::active()->where('role', 'admin')->where('id', '!=', $user->id)->exists();
    }

    private function isCreating(): bool
    {
        return $this->route('user') === null;
    }

    /**
     * Only demanded when this save is switching an existing, work-holding user
     * off. Creating a user, or editing one who stays active, strands nothing.
     */
    protected function departingUser(): ?User
    {
        $user = $this->route('user');

        return $user && $user->is_active && ! $this->boolean('is_active') ? $user : null;
    }

    public function messages(): array
    {
        return [
            'password.min'       => 'The password must be at least 8 characters.',
            'password.confirmed' => 'The two passwords do not match.',
            'mobile_number.digits' => 'Enter a 10 digit mobile number.',
            'role.in'            => 'Choose telecaller or salesperson. Admins are not created from this screen.',
        ];
    }

    /**
     * The user's own columns, hashing and handover fields aside. `permissions`
     * is normalised here rather than in the controller so the controller only
     * ever writes what has already been checked.
     */
    public function userAttributes(): array
    {
        $data = $this->safe()->only([
            'first_name', 'last_name', 'email', 'mobile_number', 'role',
        ]);

        $data['is_active']   = $this->boolean('is_active');
        $data['permissions'] = $this->normalisedPermissions();

        // blank means unchanged; never write an empty password
        if ($this->filled('password')) {
            $data['password'] = $this->input('password');
        }

        return $data;
    }

    /**
     * Booleans, keyed only by permissions that actually exist — or null.
     *
     * Null is not "none": it means "this user follows their role", which is
     * what every existing row already says and what the column was added
     * nullable for. So a set that matches the role's defaults exactly is
     * stored as null rather than as five booleans that happen to agree with
     * them today. Otherwise every save of an untouched user — an admin fixing
     * a surname — would quietly pin that person to a snapshot of the defaults
     * and detach them from the role for good.
     *
     * Anything that differs is written in full, never as a diff. A stored diff
     * would change meaning the day somebody edited config, and a toggle an
     * admin deliberately switched off would come back on by itself. Between
     * the two, "follows the role" is the one that should track config and
     * "customised" is the one that should not.
     *
     * The role used is the submitted one, which may be the role this same save
     * is changing them to — the defaults that matter are the ones they are
     * landing on, not the ones they are leaving.
     *
     * @return ?array<string, bool>
     */
    private function normalisedPermissions(): ?array
    {
        $sent = (array) $this->input('permissions', []);

        $resolved = collect(array_keys(config('crm.permissions')))
            ->mapWithKeys(fn (string $key) => [$key => (bool) ($sent[$key] ?? false)])
            ->all();

        $defaults = collect(array_keys(config('crm.permissions')))
            ->mapWithKeys(fn (string $key) => [
                $key => (bool) (config("crm.permission_defaults.{$this->input('role')}.{$key}") ?? false),
            ])
            ->all();

        return $resolved === $defaults ? null : $resolved;
    }
}
