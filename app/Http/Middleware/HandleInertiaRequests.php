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
                    /*
                     | Resolved server-side, because it is a permission and not
                     | a role: a sales manager granted see_all_leads is not an
                     | admin but does see the whole pipeline. AppLayout reads it
                     | to decide whether the Reports group lists its assigned-to
                     | links, so the sidebar offers exactly what
                     | ReportController::dimensions() offers. Presentation only —
                     | scopeVisibleTo is what actually decides which rows come
                     | back, whatever the sidebar shows.
                     */
                    'seeAllLeads' => $user->can_('see_all_leads'),
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
