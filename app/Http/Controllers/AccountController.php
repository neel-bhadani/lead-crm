<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * My profile. Every role has one, and it edits exactly one row: yours.
 *
 * At /account and not /profile. That URL belonged to Breeze's controller,
 * whose DELETE hard-deleted the signed-in account past the whole handover
 * flow; it stays a 404 so nothing that remembers it finds a door.
 *
 * There is no delete here either, and there must not be one. Removing a person
 * means handing their open leads and follow-ups to somebody first, which is a
 * decision only an admin can make — see DeleteUserRequest and
 * UserHandoverService. Somebody leaving asks an admin.
 */
class AccountController extends Controller
{
    public function edit(Request $request)
    {
        $user = $request->user();

        return Inertia::render('Account/Edit', [
            'account' => [
                'first_name'    => $user->first_name,
                'last_name'     => $user->last_name,
                'email'         => $user->email,
                'mobile_number' => $user->mobile_number,
            ],
            /*
             | Shown, never sent back. The page tells people what their role
             | and permissions are so a telecaller wondering why there is no
             | Add lead button can find out, and says who to ask about it.
             | ProfileRequest refuses all of these if they are posted anyway.
             */
            'access' => [
                'role'        => config("crm.role_words.{$user->role}", $user->role),
                'permissions' => collect(config('crm.permissions'))
                    ->map(fn (array $meta, string $key) => [
                        'label' => $meta['label'],
                        'on'    => $user->can_($key),
                    ])
                    ->values(),
            ],
        ]);
    }

    public function update(ProfileRequest $request)
    {
        $data = $request->profileAttributes();

        $request->user()->update($data);

        return back()->with('success', isset($data['password'])
            ? 'Profile and password updated.'
            : 'Profile updated.');
    }
}
