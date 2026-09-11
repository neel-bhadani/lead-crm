<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ValidatesAccountFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * A stranger asking for an account.
 *
 * Nothing this form accepts lets anybody in. The row it writes is switched off
 * and `pending` until an admin approves it — see SignupController — so what is
 * being guarded here is the shape of the request, not access:
 *
 *   the role    telecaller or salesperson, by the same rule the Users page
 *               applies. `role=admin` posted straight at the route is refused
 *               here, not merely missing from the dropdown.
 *
 *   the rate    every POST counts, pass or fail, and so does every account it
 *               makes. See ensureIsNotRateLimited().
 */
class SignupRequest extends FormRequest
{
    use ValidatesAccountFields;

    /** Submissions per IP per minute — the same five sign-in allows. */
    private const MAX_ATTEMPTS = 5;

    /** Accounts per IP per hour. Room for an office onboarding a team, not for a script. */
    private const MAX_ACCOUNTS = 5;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The rate limit runs before validation, which is the reason it lives here
     * and not in the controller: a failed submission is exactly the one a
     * script working through a list of email addresses makes, and by the time
     * a controller runs the validator has already answered it.
     */
    protected function prepareForValidation(): void
    {
        $this->ensureIsNotRateLimited();

        RateLimiter::hit($this->attemptsKey(), 60);
    }

    public function rules(): array
    {
        /*
         | One sentence for a live account and a deleted one alike. The Users
         | page tells an admin which it was, because they can restore the
         | deleted one; an anonymous visitor cannot, and has no business
         | learning that somebody used to work here.
         */
        $email  = 'That email is already registered.';
        $mobile = 'That mobile number is already registered.';

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
            'email' => [
                'required', 'email', 'max:150',
                $this->uniqueAmongUsers('email', null, $email, $email),
            ],
            'mobile_number' => [
                'required', 'digits:10',
                $this->uniqueAmongUsers('mobile_number', null, $mobile, $mobile),
            ],
            'role'     => ['required', $this->staffRoleRule()],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.min'         => 'The password must be at least 8 characters.',
            'password.confirmed'   => 'The two passwords do not match.',
            'mobile_number.digits' => 'Enter a 10 digit mobile number.',
            'role.in'              => 'Choose telecaller or salesperson.',
        ];
    }

    /**
     * The columns the new row takes from the form. Nothing else from the
     * request reaches the model — `is_active`, `approval_status` and
     * `permissions` are set by the controller, never read from input.
     *
     * The password goes in plain: the model's `hashed` cast is the only thing
     * in this application that hashes one.
     */
    public function accountAttributes(): array
    {
        return $this->safe()->only([
            'first_name', 'last_name', 'email', 'mobile_number', 'role', 'password',
        ]);
    }

    /* ---------------- rate limiting ---------------- */

    /**
     * Two limits, because there are two things to stop.
     *
     * Attempts: every submission, successful or not. The uniqueness messages
     * above say whether an address is registered, which is unavoidable on a
     * sign-up form and the reason it cannot be free to ask.
     *
     * Accounts: each one made raises an alert to every admin. Without a cap
     * on those, five attempts a minute is still seven thousand pending rows
     * and seven thousand bell notifications by morning.
     *
     * Both keyed by IP alone. Keying on the email as sign-in does would let a
     * script rotate addresses and never touch the limit.
     */
    public function ensureIsNotRateLimited(): void
    {
        foreach ([[$this->attemptsKey(), self::MAX_ATTEMPTS], [$this->accountsKey(), self::MAX_ACCOUNTS]] as [$key, $max]) {
            if (! RateLimiter::tooManyAttempts($key, $max)) {
                continue;
            }

            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'signup' => $seconds < 120
                    ? "Too many sign-up attempts. Please try again in {$seconds} seconds."
                    : 'Too many sign-up attempts. Please try again in ' . ceil($seconds / 60) . ' minutes.',
            ]);
        }
    }

    /** Called by the controller once the row exists. */
    public function recordAccountCreated(): void
    {
        RateLimiter::hit($this->accountsKey(), 3600);
    }

    private function attemptsKey(): string
    {
        return 'signup-attempts|' . $this->ip();
    }

    private function accountsKey(): string
    {
        return 'signup-accounts|' . $this->ip();
    }
}
