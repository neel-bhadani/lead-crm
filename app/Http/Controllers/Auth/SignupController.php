<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SignupRequest;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asking for an account. Not getting one.
 *
 * The row written here cannot sign in: it is switched off and `pending` until
 * an admin approves it on the Users page, and LoginRequest refuses anything
 * that is not both active and approved. Nobody is logged in at the end of this
 * — the person lands on a page that tells them what happens next.
 *
 * Deliberately not at /register. That URL was Breeze's, it answered to none of
 * this, and it stays a 404 so nothing that remembers it finds a door.
 */
class SignupController extends Controller
{
    /** The sign-in page, opened on its other tab. */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'tab'   => 'signup',
            'roles' => self::roleOptions(),
        ]);
    }

    public function store(SignupRequest $request, AlertService $alerts): RedirectResponse
    {
        $user = DB::transaction(function () use ($request, $alerts) {
            $user = new User($request->accountAttributes());

            /*
             | Set here and nowhere near the request. None of these three is
             | fillable from input in any sense that matters: the form cannot
             | switch itself on, approve itself, or arrive holding permissions.
             | Null permissions is "whatever the role does", which is what an
             | approved account starts on — the admin adjusts from there.
             */
            $user->forceFill([
                'is_active'       => false,
                'approval_status' => 'pending',
                'permissions'     => null,
            ])->save();

            /*
             | Without this nobody knows the request exists. Every active
             | admin, one alert each, keyed on the new user's id so that two
             | sign-ups on the same day are two alerts rather than the second
             | being deduplicated into the first.
             |
             | The link resets the Users page's stored filters and asks for
             | Pending, so an admin who last left it filtered to telecallers
             | still finds a salesperson waiting.
             */
            $alerts->raiseMany(
                recipients: $alerts->admins(),
                type: $user->approvalAlertType(),
                title: "{$user->display_name} is waiting for approval",
                body: 'Signed up as a ' . strtolower(config("crm.role_words.{$user->role}", $user->role))
                    . " with {$user->email}. Approve or reject the request on the Users page.",
                severity: 'warning',
                actionUrl: route('users.index', ['reset' => 1, 'status' => 'pending']),
            );

            return $user;
        });

        $request->recordAccountCreated();

        return redirect()->route('signup.submitted')->with('signedUp', [
            'name'  => $user->first_name,
            'email' => $user->email,
        ]);
    }

    /**
     * The confirmation. Readable on its own — a refresh, or a bookmark, still
     * explains what happens next — and personal only on the first visit, when
     * the flash says whose request it was.
     */
    public function submitted(): Response
    {
        return Inertia::render('Auth/SignupSubmitted', [
            'account' => session('signedUp'),
        ]);
    }

    /**
     * The roles a stranger may ask for, written out in full for a dropdown.
     * config('crm.staff_roles') is the list SignupRequest validates against,
     * so the control cannot offer something the rule would refuse.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function roleOptions(): array
    {
        return collect(config('crm.staff_roles'))
            ->map(fn (string $role) => ['value' => $role, 'label' => config("crm.role_words.{$role}", $role)])
            ->values()
            ->all();
    }
}
