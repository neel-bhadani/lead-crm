<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesFilters;
use App\Http\Requests\DeleteUserRequest;
use App\Http\Requests\UserRequest;
use App\Models\User;
use App\Services\UserHandoverService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Staff management. Every route here is behind `role:admin` — see routes/web.php,
 * where the middleware sits on the group rather than on each route.
 */
class UserController extends Controller
{
    use ResolvesFilters;

    public function __construct(private UserHandoverService $handover) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);

        /*
         | Two counts per row, and they are the reason this table exists rather
         | than a list of names: an admin about to switch somebody off needs to
         | know what that person is holding before they do it, not after. Both
         | are constrained — open leads and pending tasks, not lifetime totals
         | — because those are exactly the rows a handover would have to move.
         |
         | withCount rather than a per-row query: three subselects on one page
         | of users instead of N round trips.
         */
        $users = User::query()
            ->when($filters['search'] ?? null, function ($q, $s) {
                $q->where(function ($w) use ($s) {
                    $w->where('first_name', 'like', "%$s%")
                        ->orWhere('last_name', 'like', "%$s%")
                        ->orWhere('email', 'like', "%$s%")
                        ->orWhere('mobile_number', 'like', "%$s%");
                });
            })
            ->when($filters['role'] ?? null, fn ($q, $v) => $q->where('role', $v))
            ->when(
                // 'all' is absence; the two real values are strings because
                // that is what a <select> sends
                isset($filters['status']),
                fn ($q) => $q->where('is_active', $filters['status'] === 'active')
            )
            ->withCount([
                'leads as open_leads_count' => fn ($q) => $q->open(),
                'todos as pending_todos_count' => fn ($q) => $q->where('status', 'pending'),
                // only used to warn before a demotion — see the modal
                'leads as advanced_leads_count' => fn ($q) => $q->whereIn('stage', config('crm.advanced_stages')),
            ])
            ->orderByRaw("CASE role WHEN 'admin' THEN 0 WHEN 'salesperson' THEN 1 ELSE 2 END")
            ->orderBy('first_name')
            ->paginate(15)
            ->through(fn (User $u) => [
                'id'            => $u->id,
                'display_name'  => $u->display_name,
                'first_name'    => $u->first_name,
                'last_name'     => $u->last_name,
                'email'         => $u->email,
                'mobile_number' => $u->mobile_number,
                'role'          => $u->role,
                'is_active'     => $u->is_active,
                'open_leads_count'     => $u->open_leads_count,
                'pending_todos_count'  => $u->pending_todos_count,
                'advanced_leads_count' => $u->advanced_leads_count,
                /*
                 | Resolved, not raw. The modal shows the value in force, so a
                 | user who has never been touched shows their role's defaults
                 | rather than five blanks — and saving them writes exactly
                 | what was on screen.
                 */
                'permissions'   => $u->effectivePermissions(),
                // null until somebody opens the permissions tab; the table
                // uses it to say "role defaults" rather than "customised"
                'has_custom_permissions' => $u->permissions !== null,
            ]);

        return Inertia::render('Users/Index', [
            'users'   => $users,
            'filters' => $filters,
            'options' => [
                // every role, for the filter and the table's label lookup
                'roleLabels'  => config('crm.role_labels'),
                // the two this screen may hand out; admin is not among them
                'staffRoles'  => config('crm.staff_roles'),
                'permissions' => config('crm.permissions'),
                'permissionDefaults' => config('crm.permission_defaults'),
                /*
                 | Who a handover can go to, grouped by role so the dialog can
                 | offer only same-role destinations without doing the filtering
                 | itself. Active users only — the rule in HandsOverWork checks
                 | the same thing on the way back in.
                 */
                'assignable'  => User::active()
                    ->orderBy('first_name')
                    ->get(['id', 'first_name', 'last_name', 'role'])
                    ->map(fn ($u) => [
                        'id' => $u->id, 'name' => $u->display_name, 'role' => $u->role,
                    ]),
                // the two guards the UI greys out before the server repeats them
                'currentUserId'    => $request->user()->id,
                'activeAdminCount' => User::active()->where('role', 'admin')->count(),
            ],
        ]);
    }

    /** The filters this page owns. No defaults: the whole staff list is the start. */
    private function filters(Request $request): array
    {
        return $this->resolveFilters(
            $request,
            'users',
            [
                'search' => ['sometimes', 'string', 'max:100'],
                'role'   => ['sometimes', 'string', Rule::in(array_keys(config('crm.role_labels')))],
                'status' => ['sometimes', 'string', 'in:active,inactive'],
            ],
        );
    }

    public function store(UserRequest $request)
    {
        // password included, hashed by the model's cast and nowhere else
        User::create($request->userAttributes());

        return back()->with('success', 'User added.');
    }

    public function update(UserRequest $request, User $user)
    {
        $wasActive = $user->is_active;

        /*
         | The edit and the handover are one operation. An admin deactivating
         | somebody has said, in the same submit, where that person's work is
         | to go — a save that committed the deactivation and then failed to
         | move the leads would leave them held by a user who can no longer log
         | in to work them.
         */
        $result = DB::transaction(function () use ($request, $user, $wasActive) {
            $user->update($request->userAttributes());

            if ($wasActive && ! $user->is_active) {
                return $this->handover->transfer(
                    $user,
                    $request->handoverTarget(),
                    $request->user(),
                );
            }

            return null;
        });

        $response = back()->with('success', 'User updated.');

        // say what moved; the admin cannot see it from the page they land on
        $notice = $result ? $this->handover->summarise($result, $request->handoverTarget()) : null;

        return $notice ? $response->with('warning', $notice) : $response;
    }

    /**
     * Soft delete, with the same handover the deactivate path runs.
     *
     * The order matters: the work moves first, then the row is deleted. The
     * reverse would run the transfer's UPDATE against a user the application
     * had already stopped counting as present.
     */
    public function destroy(DeleteUserRequest $request, User $user)
    {
        $target = $request->handoverTarget();

        $result = DB::transaction(function () use ($request, $user, $target) {
            $moved = $this->handover->transfer($user, $target, $request->user());

            // deactivated as well as deleted: `role:admin` and the login both
            // check is_active, and a restored user should come back switched
            // off rather than quietly working again
            $user->update(['is_active' => false]);
            $user->delete();

            return $moved;
        });

        $response = back()->with('success', 'User deleted.');
        $notice   = $this->handover->summarise($result, $target);

        return $notice ? $response->with('warning', $notice) : $response;
    }
}
