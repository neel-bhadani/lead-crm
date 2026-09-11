<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAccountFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing your own account, and only your own.
 *
 * There is no user in the route. The row being edited is `$this->user()` and
 * nothing else, so there is no id to swap for somebody else's.
 *
 * Five columns are yours: first name, last name, email, mobile and password.
 * The rest of the row is the admin's — which role you hold, what you are
 * allowed to do, whether you can sign in at all, and whether anybody ever
 * agreed to have you. A salesperson who could post `permissions[see_all_leads]`
 * here would be a salesperson who could read the whole pipeline.
 *
 * Two locks on that, neither of them the form. Posting any of those fields is
 * refused outright (`prohibited`), so tampering is a 422 and not a silent
 * no-op somebody might mistake for success; and profileAttributes() writes an
 * explicit list regardless, so a column nobody thought to prohibit still
 * cannot ride in on the request.
 */
class ProfileRequest extends FormRequest
{
    use ValidatesAccountFields;

    /** The admin's columns. Each one's refusal says whose it is — see messages(). */
    private const ADMIN_ONLY = ['role', 'permissions', 'is_active', 'approval_status'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $me = $this->user();

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],

            /*
             | Unique among everybody else, trashed rows included — the index
             | does not know about soft deletes. One sentence for both kinds of
             | clash: restoring a deleted account is the admin's move, not the
             | person asking.
             */
            'email' => [
                'required', 'email', 'max:150',
                $this->uniqueAmongUsers('email', $me,
                    'That email is already in use.', 'That email is already in use.'),
            ],
            'mobile_number' => [
                'required', 'digits:10',
                $this->uniqueAmongUsers('mobile_number', $me,
                    'That mobile number is already in use.', 'That mobile number is already in use.'),
            ],

            /*
             | Blank means unchanged, the Users page convention. Setting one
             | takes the current password as well — a session left open on a
             | shared desk must not be enough to lock its owner out.
             */
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password'         => ['nullable', 'string', 'min:8', 'confirmed'],
        ] + array_fill_keys(self::ADMIN_ONLY, ['prohibited']);
    }

    public function messages(): array
    {
        return [
            'current_password.required_with'    => 'Enter your current password to set a new one.',
            'current_password.current_password' => 'That is not your current password.',
            'password.min'                      => 'The password must be at least 8 characters.',
            'password.confirmed'                => 'The two passwords do not match.',
            'mobile_number.digits'              => 'Enter a 10 digit mobile number.',
            'role.prohibited'                   => 'Your role is set by an administrator.',
            'permissions.prohibited'            => 'Your permissions are set by an administrator.',
            'is_active.prohibited'              => 'Your account status is set by an administrator.',
            'approval_status.prohibited'        => 'Your approval status is set by an administrator.',
        ];
    }

    /**
     * What the save writes: this list and nothing else, whatever the request
     * carried. The password goes in plain — the model's `hashed` cast is the
     * only thing in the application that hashes one.
     */
    public function profileAttributes(): array
    {
        $data = $this->safe()->only(['first_name', 'last_name', 'email', 'mobile_number']);

        if ($this->filled('password')) {
            $data['password'] = $this->input('password');
        }

        return $data;
    }
}
