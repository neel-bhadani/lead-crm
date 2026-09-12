<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The alerts list, shared by the two screens that show it.
 *
 * /alerts is everybody's — a telecaller has a bell too, and a page behind
 * `role:admin` would give them a count they could never open. The Automation
 * page's Alerts tab is the admin's own list, with the built-in thresholds
 * printed beside it, and it must show exactly the same rows in the same order
 * as the page the bell links to.
 *
 * One method, because two would drift: the day somebody adds a severity, the
 * copy that was not updated starts silently dropping rows.
 *
 * The filters live in the session, like every other page's — the front end
 * wipes them off the address bar, and marking an alert read redirects back to
 * that bare address. Each screen keeps its own entry, as each had its own
 * query string before: a severity picked on the Automation tab does not follow
 * the reader to /alerts.
 */
trait ListsAlerts
{
    use ResolvesFilters;

    /**
     * @param  string  $page  the session entry these filters are kept under
     * @return array{alerts: array, filters: array, counts: array}
     */
    protected function alertList(Request $request, User $user, string $page = 'alerts'): array
    {
        $filters = $this->resolveFilters(
            $request,
            $page,
            [
                'status' => ['sometimes', 'string', 'in:all,unread,read'],
                'severity' => ['sometimes', 'string', 'in:all,info,warning,urgent'],
            ],
            ['status' => 'all', 'severity' => 'all'],
        );

        $alerts = Alert::for($user)
            ->when($filters['status'] === 'unread', fn ($q) => $q->unread())
            ->when($filters['status'] === 'read', fn ($q) => $q->read())
            ->when($filters['severity'] !== 'all', fn ($q) => $q->where('severity', $filters['severity']))
            ->with([
                'lead:id,first_name,middle_name,last_name,mobile_number,stage',
                'rule:id,name',
            ])
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();

        return [
            'alerts' => $alerts,
            'filters' => $filters,
            'counts' => [
                'all' => Alert::for($user)->count(),
                'unread' => Alert::for($user)->unread()->count(),
                // what the severity chips print, over the whole list rather
                // than the filtered one — a chip that counted only what is
                // already on screen would always read the same as the rows
                'info' => Alert::for($user)->where('severity', 'info')->count(),
                'warning' => Alert::for($user)->where('severity', 'warning')->count(),
                'urgent' => Alert::for($user)->where('severity', 'urgent')->count(),
            ],
        ];
    }
}
