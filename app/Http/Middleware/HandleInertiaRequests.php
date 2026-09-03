<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [

            'auth' => [
                'user' => $user ? [
                    'id'    => $user->id,
                    'name'  => $user->display_name,
                    'email' => $user->email,
                    'role'  => $user->role,
                ] : null,
            ],

            // read once in AppLayout and shown as a toast
            'flash' => [
                'success' => fn() => $request->session()->get('success'),
                'error'   => fn() => $request->session()->get('error'),
                // things the app decided on its own — an auto-lost lead, a handover
                'warning' => fn() => $request->session()->get('warning'),
            ],
        ]);
    }
}
