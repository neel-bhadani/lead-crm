<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesDateRange;
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
    use ResolvesDateRange, ResolvesFilters;

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

        [$from, $to] = $this->dateWindow($filters);
        $column      = $this->dateColumn($tab);

        /*
         | One base query, built once and read twice.
         |
         | The type chips answer "how would this list break down by type" —
         | this list, this tab, every other filter applied, and without the type
         | filter itself. Selecting Call must not leave the other three chips
         | reading zero.
         |
         | So the type clause is the one thing this closure leaves out: the page
         | of rows adds it, the counts do not. The tab is inside it, which is
         | the difference from the Leads page — a tab is not a view of one list,
         | it is a different list, so switching tabs has to recompute the chips.
         |
         | forUser() and hasLead() are inside it too, so a telecaller's chips
         | count a telecaller's tasks and a soft-deleted lead takes its rows out
         | of the counts exactly as it takes them out of the table.
         */
        $base = fn() => $this->applyTab(
            Todo::forUser($user)
                // a deleted lead takes its rows off this page with it
                ->hasLead()
                ->when($filters['search'] ?? null, function ($q, $s) {
                    $q->whereHas('lead', function ($w) use ($s) {
                        $w->where('first_name', 'like', "%$s%")
                            ->orWhere('last_name', 'like', "%$s%")
                            ->orWhere('mobile_number', 'like', "%$s%");
                    });
                })
                ->when($filters['assigned_to'] ?? null, fn($q, $v) => $q->where('assigned_to', $v)),
            $tab
        )->when($from, fn($q) => $q->whereBetween($column, [$from, $to]));

        $rows = $base()
            ->when($filters['type'] ?? null, fn($q, $v) => $q->where('type', $v))
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
            ]);

        $rows = $tab === 'completed'
            ? $rows->latest('completed_at')
            : $rows->orderBy('scheduled_at');

        return Inertia::render('Todos/Index', [
            // no withQueryString(): the filters are in the session now, so a
            // page link carries nothing but its page number
            'todos'   => $rows->paginate(15)->through(fn($todo) => $this->withPreviews($todo)),
            'tab'     => $tab,
            /*
             | The tab badges, and they are deliberately not the chips. They
             | answer "how much is in each list" and take no filter at all, so
             | selecting a type chip cannot move them — the number on Completed
             | is the number of completed tasks, not the number of completed
             | calls.
             */
            'counts'  => $this->counts($user),
            'types'   => $this->typeCounts($base),
            'filters' => $this->withRangeWord($filters),
            'options' => [
                'stages'      => config('crm.stages'),
                'stageColors' => config('crm.stage_colors'),
                'types'       => config('crm.todo_types'),
                // today in IST. The date inputs use this as their max rather
                // than the browser clock, which may be in another timezone.
                'today'       => today()->toDateString(),
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
        return $this->resolveFilters(
            $request,
            'todos',
            [
                'tab'         => ['sometimes', 'string', 'in:overdue,today,upcoming,completed'],
                'search'      => ['sometimes', 'string', 'max:100'],
                'type'        => ['sometimes', 'string', Rule::in(array_keys(config('crm.todo_types')))],
                'assigned_to' => ['sometimes', 'integer', 'min:1'],
            ] + $this->dateRangeRules(),
            ['tab' => self::DEFAULT_TAB],
            fn(array $state) => $this->sanitiseDates($state),
        );
    }

    /**
     * The rows a tab is made of. Ordering is not here: the chip counts run
     * through this too, and an ORDER BY on a GROUP BY is work for nothing.
     */
    private function applyTab($query, string $tab)
    {
        return match ($tab) {
            'overdue'   => $query->overdue(),
            'upcoming'  => $query->upcoming(),
            'completed' => $query->where('status', 'completed'),
            default     => $query->dueToday(),
        };
    }

    /**
     * Which date the date filter means, which is not the same question on
     * every tab.
     *
     * A pending task is a plan, so the date that matters is when it is due. A
     * completed one is a thing that happened, so the date that matters is when
     * it happened — "what did we get done last week" is the question, and
     * answering it from scheduled_at would count a task planned last week and
     * closed today, while missing one planned in March and closed on Tuesday.
     *
     * The return value is a literal chosen here, never user input, which is
     * what makes it safe to hand to whereBetween as a column name.
     */
    private function dateColumn(string $tab): string
    {
        return $tab === 'completed' ? 'completed_at' : 'scheduled_at';
    }

    /**
     * The type breakdown of this tab, as one grouped query.
     *
     * Zero-filled across every configured type, so a type nobody has any of is
     * a chip reading 0 rather than a chip that is not there — a missing chip
     * reads as a bug, and the row would reflow every time a filter changed.
     *
     * The total is summed from the chips rather than counted again. It is the
     * "All" chip, and "All" disagreeing with the four beside it is the one
     * failure this feature cannot survive.
     *
     * @param  callable(): \Illuminate\Database\Eloquent\Builder  $base
     * @return array{total: int, bars: list<array{key: string, label: string, value: int}>}
     */
    private function typeCounts(callable $base): array
    {
        $counts = $base()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $bars = collect(config('crm.todo_types'))
            ->map(fn($label, $key) => [
                'key'   => $key,
                'label' => $label,
                'value' => (int) ($counts[$key] ?? 0),
            ])->values()->all();

        return ['total' => array_sum(array_column($bars, 'value')), 'bars' => $bars];
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
