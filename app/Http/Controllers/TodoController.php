<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesFilters;
use App\Http\Requests\CompleteTodoRequest;
use App\Http\Requests\TodoRequest;
use App\Models\Lead;
use App\Models\Todo;
use App\Models\User;
use App\Services\FollowUpScheduler;
use App\Services\LeadFollowUpService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TodoController extends Controller
{
    use ResolvesFilters;

    /** The tab a visit lands on when nothing says otherwise. */
    private const DEFAULT_TAB = 'today';

    public function __construct(
        private LeadFollowUpService $service,
        private FollowUpScheduler $scheduler
    ) {}

    public function index(Request $request)
    {
        $user    = $request->user();
        $filters = $this->filters($request);
        $tab     = $filters['tab'];

        $query = Todo::forUser($user)
            // a deleted lead takes its rows off this page with it
            ->hasLead()
            ->with([
                /*
                 | stage_changed_at and created_at are not shown here, but the
                 | Lead model appends days_in_stage, and that accessor reads
                 | them. Leaving them out of a constrained select does not
                 | throw — the attribute simply reads as null and the row ships
                 | a silent "unknown" instead of a number.
                 */
                'lead:id,first_name,middle_name,last_name,mobile_number,stage,project_id,stage_changed_at,created_at,not_connected_count',
                'lead.project:id,name',
                // role too: the Handled by column stacks it under the name
                'owner:id,first_name,last_name,role',
                'completer:id,first_name,last_name',
            ])
            ->when($filters['search'] ?? null, function ($q, $s) {
                $q->whereHas('lead', function ($w) use ($s) {
                    $w->where('first_name', 'like', "%$s%")
                        ->orWhere('last_name', 'like', "%$s%")
                        ->orWhere('mobile_number', 'like', "%$s%");
                });
            })
            ->when($filters['type'] ?? null, fn($q, $v) => $q->where('type', $v))
            ->when($filters['assigned_to'] ?? null, fn($q, $v) => $q->where('assigned_to', $v));

        $query = match ($tab) {
            'overdue'   => $query->overdue()->orderBy('scheduled_at'),
            'upcoming'  => $query->upcoming()->orderBy('scheduled_at'),
            'completed' => $query->where('status', 'completed')->latest('completed_at'),
            default     => $query->dueToday()->orderBy('scheduled_at'),
        };

        return Inertia::render('Todos/Index', [
            // no withQueryString(): the filters are in the session now, so a
            // page link carries nothing but its page number
            'todos'   => $query->paginate(15)->through(fn($todo) => $this->withPreviews($todo)),
            'tab'     => $tab,
            'counts'  => $this->counts($user),
            'filters' => $filters,
            'options' => [
                'stages'      => config('crm.stages'),
                'stageColors' => config('crm.stage_colors'),
                'types'       => config('crm.todo_types'),
                'reasons'     => config('crm.lost_reasons'),
                // CallButtons builds its tel: and wa.me hrefs from this
                'countryCode' => config('crm.country_code'),
                'roleLabels'  => config('crm.role_labels'),
                'openLeads'   => Lead::visibleTo($user)->open()
                    ->doesntHave('pendingTodo')
                    ->get(['id', 'first_name', 'last_name', 'mobile_number']),
                'users'       => $user->isAdmin()
                    ? User::whereIn('role', ['telecaller', 'salesperson'])
                    ->get(['id', 'first_name', 'last_name'])
                    : [],
            ],
        ]);
    }

    /**
     * The filters this page owns. `tab` is one of them — it is a filter like
     * any other, and it is what the dashboard's follow-up panels pass when
     * they link across, so it has to be honoured on the way in even though
     * the front end wipes it off the address bar on the way out.
     */
    private function filters(Request $request): array
    {
        return $this->resolveFilters($request, 'todos', [
            'tab'         => ['sometimes', 'string', 'in:overdue,today,upcoming,completed'],
            'search'      => ['sometimes', 'string', 'max:100'],
            'type'        => ['sometimes', 'string', Rule::in(array_keys(config('crm.todo_types')))],
            'assigned_to' => ['sometimes', 'integer', 'min:1'],
        ], ['tab' => self::DEFAULT_TAB]);
    }

    /**
     * Log the call: closes this task, moves the stage, schedules the next one.
     */
    public function complete(CompleteTodoRequest $request, Todo $todo)
    {
        abort_if($todo->status !== 'pending', 422, 'This task is already closed.');

        $outcome = $this->service->complete(
            todo: $todo,
            stage: $request->stage,
            remarks: $request->remarks,
            visitAt: $request->visit_at ? Carbon::parse($request->visit_at) : null,
            extra: $request->only('reason', 'booked_unit', 'booking_date'),
        );

        $response = back()->with('success', $outcome['auto_lost']
            ? 'Call logged.'
            : 'Call logged and next follow-up scheduled.');

        // the service decided something on its own — say so
        $notice = $this->service->noticeFor($outcome);

        return $notice ? $response->with('warning', $notice) : $response;
    }

    public function store(TodoRequest $request)
    {
        $lead = Lead::findOrFail($request->lead_id);

        abort_unless(
            $request->user()->isAdmin() || $lead->assigned_to === $request->user()->id,
            403
        );

        Todo::create([
            'lead_id'      => $lead->id,
            'assigned_to'  => $lead->assigned_to,
            'created_by'   => $request->user()->id,
            'scheduled_at' => $request->scheduled_at,
            'type'         => $request->type,
            'status'       => 'pending',
            'remarks'      => $request->remarks,
        ]);

        return back()->with('success', 'To-do added.');
    }

    public function update(TodoRequest $request, Todo $todo)
    {
        abort_if($todo->status !== 'pending', 422, 'Completed tasks cannot be edited.');

        abort_unless(
            $request->user()->isAdmin() || $todo->assigned_to === $request->user()->id,
            403
        );

        $todo->update($request->only('scheduled_at', 'type', 'remarks'));

        return back()->with('success', 'Task rescheduled.');
    }

    public function destroy(Request $request, Todo $todo)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $todo->update(['status' => 'cancelled']);

        return back()->with('success', 'Task cancelled.');
    }

    /**
     * The line CompleteTaskModal shows for each stage in its dropdown.
     *
     * It is per lead because the retry ladder is: the same "not connected" on
     * a third attempt is 48 hours away, not 4. Working hours and holidays are
     * only known here, so the front end is handed the answer rather than the
     * arithmetic.
     */
    private function withPreviews(Todo $todo): Todo
    {
        return $todo->setAttribute(
            'follow_up_previews',
            $this->scheduler->previews($todo->lead)
        );
    }

    private function counts($user): array
    {
        // hasLead() on every one of them: a badge that counted rows the tab
        // does not render would be worse than the crash it replaced
        return [
            'overdue'   => Todo::forUser($user)->hasLead()->overdue()->count(),
            'today'     => Todo::forUser($user)->hasLead()->dueToday()->count(),
            'upcoming'  => Todo::forUser($user)->hasLead()->upcoming()->count(),
            'completed' => Todo::forUser($user)->hasLead()->where('status', 'completed')->count(),
        ];
    }
}
