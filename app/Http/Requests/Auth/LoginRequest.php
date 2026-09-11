<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Replaces Breeze's LoginRequest so a user can sign in with
 * either an email address or a mobile number.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.required' => 'Enter your email or mobile number.',
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $login = $this->input('login');

        // decide which column to match on
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'mobile_number';

        $credentials = [
            $field      => $login,
            'password'  => $this->input('password'),
            'is_active' => true,          // a disabled user cannot sign in
            /*
             | Nor can one nobody approved. A pending account is already
             | switched off, so is_active alone refuses it; this is the lock
             | that holds if somebody flips is_active in the database, or on
             | a row the Users page has not been taught about.
             */
            'approval_status' => 'approved',
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            $waiting = $this->approvalRefusal($field, $login);

            /*
             | Under its own key when it is the approval message, so the page
             | can show it as a notice rather than as a red line under the
             | email field — the person typed nothing wrong.
             */
            throw ValidationException::withMessages(
                $waiting ? ['approval' => $waiting] : ['login' => trans('auth.failed')],
            );
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * The one failed sign-in that is allowed to say why.
     *
     * Every other failure gets one message for both wrong password and unknown
     * user, otherwise an attacker can enumerate accounts.
     *
     * The exception is an account still waiting for approval, or one that was
     * turned down. The person who just signed up already knows the account
     * exists, and "these credentials do not match" would send them round in
     * circles retyping a password that was right all along. So they are told —
     * but only once the password has checked out. Without it they get the
     * generic line like everybody else, which keeps this from being a way to
     * ask "is this address waiting for approval".
     *
     * The rate limiter has already been hit by the time this runs, so a
     * correct-password answer is exactly as expensive to fish for as a
     * successful sign-in.
     *
     * @return ?string  null when the generic message should stand
     */
    private function approvalRefusal(string $field, string $login): ?string
    {
        $provider = Auth::getProvider();
        $user     = $provider->retrieveByCredentials([$field => $login]);

        if (! $user || ! $provider->validateCredentials($user, ['password' => $this->input('password')])) {
            return null;
        }

        return match ($user->approval_status) {
            'pending'  => 'Your account is waiting for approval from an administrator.',
            'rejected' => 'Your account request was not approved. Contact your administrator.',
            // approved and switched off since: the no-enumeration rule stands
            default    => null,
        };
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(
            Str::lower($this->string('login')) . '|' . $this->ip()
        );
    }
}
