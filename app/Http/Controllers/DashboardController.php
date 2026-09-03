<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesFilters;
use App\Models\Lead;
use App\Models\Todo;
use App\Models\User;
use App\Services\FollowUpScheduler;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class DashboardController extends Controller
{
    use ResolvesFilters;

    /** Two years, the extra day so a leap year is not rejected for being itself. */
    private const MAX_SPAN_DAYS = 731;

    /**
     * Rows loaded into each follow-up panel. The panel scrolls rather than
     * paginating, so this is a ceiling on the payload, not on what is offered:
     * the header keeps the real total, and anything past it is a link to the
     * To-do page.
     */
    private const PANEL_ROWS = 50;

    /**
     * Rows listed in the sign-in notice. It is a nudge, not a work queue —
     * the count in its heading is the real total and the button next to it
     * goes to the page that holds all of them.
     */
    private const DIGEST_ROWS = 5;

    /**
     * Set the first time the notice is actually rendered, and read on every
     * later dashboard visit in the same session.
     *
     * Server-side rather than sessionStorage, deliberately. sessionStorage is
     * per browser tab, so a second tab would show the notice again and closing
     * a dismissed one would bring it back; and the client would have to be
     * sent a list it has already been told not to display, which is a payload
     * and a flash of content for nothing. Held here, a dismissed notice is not
     * hidden on the next visit — it is not sent.
     */
    private const DIGEST_SEEN = 'dashboard.digest_seen';

    public function __construct(private FollowUpScheduler $scheduler) {}

    public function index(Request $request)
    {
        $user  = $request->user();
        $range = $this->resolveRange($this->filters($request));
        $from  = $range['from'];
        $to    = $range['to'];

        return Inertia::render('Dashboard', [
            'range'   => [
                'key'   => $range['key'],
                'from'  => $from->toDateString(),
                'to'    => $to->toDateString(),
                'label' => $range['label'],
                // today in IST. The picker uses this as its max rather than the
                // browser clock, which may be in another timezone entirely.
                'today'       => today()->toDateString(),
                'maxSpanDays' => self::MAX_SPAN_DAYS,
            ],
            /*
             | Closures, not values. Logging a call from the dashboard reloads
             | only `cards` and `followUps`; a plain array would still run every
             | chart query and then have it thrown away by the partial filter.
             | Inertia only invokes a closure for a prop it is actually sending.
             */
            'cards'   => fn() => $this->cards($user, $from, $to),
            /*
             | Four charts, in the order the page draws them.
             |
             | Two of them are stock and two are flow, and they alternate: the
             | pipeline snapshot and the to-do backlog are "right now" whatever
             | the range says, while the stage changes and the source split are
             | the range. Each card carries the `snapshot` marker or does not,
             | so the pair in a row can never be mistaken for the same question
             | asked twice.
             */
            'charts'  => fn() => [
                // stock, deliberately not date filtered — see byStage()
                'byStage'      => $this->byStage($user),
                // flow, and the one that ties to the cards above it
                'stageChanges' => $this->stageChanges($user, $from, $to),
                'bySource'     => $this->bySource($user, $from, $to),
                // stock again: outstanding work, which has no date range
                'byTodoType'   => $this->byTodoType($user),
            ],
            /*
             | The sign-in notice. A closure for the same reason the rest are,
             | and for one more: invoking it is what marks the session as told,
             | and Inertia only invokes a closure for a prop it is actually
             | sending. Reloading `cards` after a logged call therefore cannot
             | burn the one showing this session was owed.
             */
            'todayDigest' => fn() => $this->todayDigest($request),
            // both panels are "right now", never filtered by the date range
            'followUps' => fn() => [
                'today'   => $this->followUps($user, 'today'),
                'overdue' => $this->followUps($user, 'overdue'),
            ],
            'options'  => [
                'stages'      => config('crm.stages'),
                'stageColors' => config('crm.stage_colors'),
                'sources'     => config('crm.sources'),
                // CompleteTaskModal needs these to offer a reason when a call
                // ends in "lost"; StageBadge inside it reads stageColors above.
                'reasons'     => config('crm.lost_reasons'),
                // CallButtons builds its tel: and wa.me hrefs from this
                'countryCode' => config('crm.country_code'),
                'roleLabels'  => config('crm.role_labels'),
            ],
        ]);
    }

    /* ---------------- date range ---------------- */

    /**
     * The filters this page owns: a named range, or a custom `from`/`to` pair.
     *
     * The values reach the query builder from the session, which the user can
     * fill by typing a query string, so a mistyped date has to leave the
     * dashboard standing rather than throw: anything that does not survive
     * `sanitiseRange()` is dropped and the 30-day default takes over.
     */
    private function filters(Request $request): array
    {
        return $this->resolveFilters(
            $request,
            'dashboard',
            [
                'range' => ['sometimes', 'string', 'in:today,7,30'],
                'from'  => ['sometimes', 'string', 'date_format:Y-m-d'],
                'to'    => ['sometimes', 'string', 'date_format:Y-m-d'],
            ],
            ['range' => '30'],
            fn(array $state) => $this->sanitiseRange($state),
        );
    }

    /**
     * What a validator cannot say about the pair: it is all or nothing, it
     * ends today at the latest, and it is not wider than two years. A pair
     * that breaks any of those is dropped here rather than at read time, so a
     * rejected range does not sit in the session being rejected again on
     * every visit.
     */
    private function sanitiseRange(array $state): array
    {
        $from = $state['from'] ?? null;
        $to   = $state['to'] ?? null;

        if ($from === null || $to === null) {
            unset($state['from'], $state['to']);

            return $state;
        }

        $today = today();   // IST — the app timezone is Asia/Kolkata
        $start = Carbon::createFromFormat('Y-m-d', $from)->startOfDay();
        $end   = Carbon::createFromFormat('Y-m-d', $to)->endOfDay();

        // backwards, in the future, or too wide to bucket usefully — and a lot
        // of rows to scan for a chart
        if ($end->lt($start)
            || $end->gt($today->copy()->endOfDay())
            || $this->spanInDays($start, $end) > self::MAX_SPAN_DAYS) {
            unset($state['from'], $state['to']);
        }

        return $state;
    }

    /** The selected range. `sanitiseRange()` has already vouched for it. */
    private function resolveRange(array $filters): array
    {
        $from = $filters['from'] ?? null;
        $to   = $filters['to'] ?? null;

        if ($from === null || $to === null) {
            return $this->preset((string) ($filters['range'] ?? '30'), today());
        }

        return $this->describe(
            'custom',
            Carbon::createFromFormat('Y-m-d', $from)->startOfDay(),
            Carbon::createFromFormat('Y-m-d', $to)->endOfDay(),
        );
    }

    /**
     * The named ranges. Each one ends today, and "last 7 days" counts today
     * as one of the seven rather than reaching back seven whole days on top
     * of it.
     *
     * Every one of them runs from startOfDay() to endOfDay(), and Today is no
     * exception. It used to end at now(), which is a different boundary from
     * the one the other two use and made Today the odd range out twice over:
     *
     *   - anything stamped later today than the moment of the request — a
     *     visit logged from a device a few minutes ahead, a row written by a
     *     job — fell out of Today while still counting in Last 7 days, which
     *     is exactly the "it is in the 30-day figure but not the 7-day one"
     *     shape, in miniature.
     *
     * A raw subDays() would keep the current time of day and silently drop
     * the earliest day's morning, so the day arithmetic happens on a
     * start-of-day value throughout.
     */
    private function preset(string $key, Carbon $today): array
    {
        return match ($key) {
            'today' => $this->describe('today', $today->copy()->startOfDay(), $today->copy()->endOfDay()),
            '7'     => $this->describe('7', $today->copy()->subDays(6)->startOfDay(), $today->copy()->endOfDay()),
            default => $this->describe('30', $today->copy()->subDays(29)->startOfDay(), $today->copy()->endOfDay()),
        };
    }

    private function describe(string $key, Carbon $from, Carbon $to): array
    {
        return [
            'key'   => $key,
            'from'  => $from,
            'to'    => $to,
            'label' => $this->rangeLabel($from, $to),
        ];
    }

    /** Whole days covered, counting both ends: 1 June to 1 June is one day. */
    private function spanInDays(Carbon $from, Carbon $to): int
    {
        return (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
    }

    /** "1 Jun – 15 Jun", or "1 Jun 25 – 15 Jun 26" when the years differ. */
    private function rangeLabel(Carbon $from, Carbon $to): string
    {
        $format = $from->year === $to->year ? 'j M' : 'j M y';

        return $from->format($format) . ' – ' . $to->format($format);
    }

    /* ---------------- KPI cards ---------------- */

    private function cards($user, Carbon $from, Carbon $to): array
    {
        // intake: these two really are about when the lead arrived
        $total = Lead::visibleTo($user)->whereBetween('created_at', [$from, $to])->count();
        $today = Lead::visibleTo($user)->whereDate('created_at', today())->count();

        /*
         | The three event cards, and the "Stage changes in this range" chart,
         | all come out of stageEvents() — one query, read four times.
         |
         | They used to be four separate queries that merely looked alike, and
         | "these must stay identical" is not something a reader can check or a
         | compiler can enforce. Now the Bookings card and the booking_done bar
         | are the same integer, so they cannot drift apart no matter what is
         | edited later. Same for Site visits and Lost.
         */
        $events = $this->stageEvents($user, $from, $to);

        $visits = $events['site_visit_done'];
        $booked = $events['booking_done'];
        $lost   = $events['lost'];

        /*
         | Conversion is a cohort figure, not $booked / $total.
         |
         | Those two now count different populations — bookings that happened in
         | the range against leads created in it — so a good week off the back of
         | older leads would read 150%. This asks one question of one population
         | instead: of the leads that came in during this range, how many have
         | booked since. Numerator is a subset of the denominator, so it cannot
         | exceed 100% and needs no clamping.
         */
        $cohortBooked = Lead::visibleTo($user)
            ->whereBetween('created_at', [$from, $to])
            // whereNotNull('completed_at') for the same reason the cards use it:
            // the numerator has to count a booking that *happened*, and only a
            // completed row is a thing that happened
            ->whereHas('todos', fn($q) => $q->where('outcome_stage', 'booking_done')
                ->whereNotNull('completed_at'))
            ->count();

        /*
         | STOCK: work outstanding right now, so no date range touches it.
         |
         | Everything pending that was due on or before today — the two panels
         | below this card, Waiting longer plus Due today, added together. It used to
         | count only the overdue half, which meant the card and the panels
         | under it disagreed by exactly today's workload.
         |
         | hasLead() drops to-dos whose lead has been deleted, the same
         | exclusion the history counts get from their whereHas.
         */
        $pending = Todo::forUser($user)->hasLead()->pending()
            ->where('scheduled_at', '<=', today()->endOfDay())
            ->count();

        /**
         * The change indicator compares the period immediately before this one,
         * of the same length and ending the day before it starts: 1–15 June is
         * measured against 17–31 May, and Today against the whole of yesterday.
         */
        $prevTo   = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->startOfDay()->subDays($this->spanInDays($from, $to) - 1);

        $prevTotal = Lead::visibleTo($user)
            ->whereBetween('created_at', [$prevFrom, $prevTo])
            ->count();

        return [
            'total'      => $total,
            'today'      => $today,
            'visits'     => $visits,
            'booked'     => $booked,
            'lost'       => $lost,
            'pending'    => $pending,
            /*
             | Cohort: of the leads created in this range, how many have since
             | reached booking_done, at any time. Numerator is a subset of the
             | denominator, so it cannot exceed 100% and needs no clamping — and
             | it is reported under Total leads, which *is* that denominator.
             |
             | A dash when there is nothing to divide by. Never 0%, never an
             | error.
             */
            'conversion' => $total > 0 ? round($cohortBooked / $total * 100, 1) : null,
            'delta'      => $prevTotal > 0 ? round(($total - $prevTotal) / $prevTotal * 100) : null,
        ];
    }

    /* ---------------- charts ---------------- */

    /**
     * STOCK, not flow: where every lead sits right now. "Pipeline right now".
     *
     * Deliberately not date-filtered, and it must stay that way. A lead's
     * current stage is a state, not an event that happened on a date, so asking
     * "which stage were the leads created last week in" answers a question
     * nobody has. Filtering it is what made a booking on an older lead vanish
     * from this chart while the Bookings card counted it.
     *
     * It will never tie to the cards, because it is not answering their
     * question — 3 bookings *today* against 7 leads *sitting at* booking done
     * are both right. The reconciling chart is stageChanges() below; this one
     * ships its own total so the header can say what population it is drawing,
     * and the page labels it as the all-time snapshot it is.
     *
     * @return array{total: int, bars: list<array{key: string, label: string, value: int, color: string}>}
     */
    private function byStage($user): array
    {
        $counts = Lead::visibleTo($user)
            ->selectRaw('stage, count(*) as total')
            ->groupBy('stage')
            ->pluck('total', 'stage');

        // zero-fill — a missing bar looks like a bug, a zero bar looks like information
        $bars = collect(config('crm.stages'))
            ->map(fn($label, $key) => [
                'key'   => $key,
                'label' => $label,
                'value' => (int) ($counts[$key] ?? 0),
                'color' => config("crm.stage_colors.$key"),
            ])->values()->all();

        /*
         | Summed from the bars, not counted again. Every lead has exactly one
         | stage and the bars are zero-filled across all of them, so the sum is
         | the count of visible leads — and taking it from the bars means the
         | header can never disagree with the chart underneath it, which is the
         | whole failure this card is here to stop repeating.
         */
        return ['total' => array_sum(array_column($bars, 'value')), 'bars' => $bars];
    }

    /**
     * FLOW, and the chart that reconciles with the cards.
     *
     * The same events the Bookings, Site visits and Lost cards count, drawn per
     * stage instead of three at a time: filtered on todos.completed_at, when the
     * transition happened, and distinct by lead. Its booking_done bar IS the
     * Bookings card — the same integer out of the same query, not a second
     * count that has to be kept in step by hand.
     *
     * Zero-filled across every configured stage, so it lines up bar for bar
     * with the pipeline snapshot and a stage nobody reached this week reads as
     * a zero rather than a missing category.
     */
    private function stageChanges($user, Carbon $from, Carbon $to): array
    {
        $counts = $this->stageEvents($user, $from, $to);

        return collect(config('crm.stages'))
            ->map(fn($label, $key) => [
                'key'   => $key,
                'label' => $label,
                'value' => $counts[$key],
                'color' => config("crm.stage_colors.$key"),
            ])->values()->all();
    }

    /**
     * Every stage transition inside the range, counted once per lead per stage.
     *
     * The single source for both the three event cards and the "Stage changes
     * in this range" chart. One grouped query rather than four near-identical
     * ones: four queries that must agree is a promise, one query read four
     * times is a fact.
     *
     *   - todos.completed_at, never leads.created_at. A lead created 40 days
     *     ago that booked today is a booking that happened today.
     *   - COUNT(DISTINCT lead_id), because one lead can reach the same stage
     *     twice inside a range and that is still one lead that got there.
     *   - visibility through the lead: the to-do may sit with a colleague while
     *     the lead itself is one this user can see. whereHas() runs the
     *     relation's own query, so the soft-delete scope comes with it and a
     *     trashed lead takes its history off the dashboard.
     *
     * @return array<string, int> zero-filled, keyed by stage
     */
    private function stageEvents($user, Carbon $from, Carbon $to): array
    {
        $counts = Todo::whereHas('lead', fn($q) => $q->visibleTo($user))
            ->whereNotNull('outcome_stage')
            ->whereBetween('completed_at', [$from, $to])
            ->selectRaw('outcome_stage, count(distinct lead_id) as total')
            ->groupBy('outcome_stage')
            ->pluck('total', 'outcome_stage');

        return collect(config('crm.stages'))
            ->map(fn($label, $key) => (int) ($counts[$key] ?? 0))
            ->all();
    }

    private function bySource($user, Carbon $from, Carbon $to): array
    {
        $counts = Lead::visibleTo($user)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('source, count(*) as total')
            ->groupBy('source')
            ->pluck('total', 'source');

        $sum = $counts->sum();

        // a pie drops empty slices, unlike a bar chart
        return collect(config('crm.sources'))
            ->map(fn($label, $key) => [
                'label'   => $label,
                'value'   => (int) ($counts[$key] ?? 0),
                'percent' => $sum > 0 ? round(($counts[$key] ?? 0) / $sum * 100, 1) : 0,
            ])
            ->filter(fn($row) => $row['value'] > 0)
            ->values()->all();
    }

    /**
     * STOCK: the work still waiting, split by the kind of work it is.
     *
     * Not date filtered, and for the same reason byStage() is not. A pending
     * to-do is a thing that has not happened yet, so "pending to-dos created
     * last week" answers nothing anyone asks — the question is what is
     * outstanding right now, which is one number whatever the picker says.
     *
     * forUser(), not visibleTo(): this is a work list, so it is scoped by who
     * owns the task, exactly as the two panels and the Pending card are.
     * hasLead() for the same reason they use it — a soft-deleted lead's rows
     * are not work anybody is going to do.
     *
     * Zero-filled across every configured type, so all four bars render even
     * when nothing of that kind is outstanding. The labels come from
     * config('crm.todo_types'); no type is spelled out here or in the Vue.
     *
     * Ordered biggest first. Config order put Call — which is nearly all of the
     * backlog — next to three near-empty bars in whatever sequence the config
     * file happened to list them, so the one bar worth reading was not
     * necessarily the one the eye landed on. Sorting is not filtering: every
     * type still has a bar, zeros included, and PHP's sort is stable, so the
     * ties among the zeros stay in config order rather than shuffling between
     * page loads.
     *
     * @return array{total: int, bars: list<array{key: string, label: string, value: int}>}
     */
    private function byTodoType($user): array
    {
        $counts = Todo::forUser($user)->hasLead()->pending()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $bars = collect(config('crm.todo_types'))
            ->map(fn($label, $key) => [
                'key'   => $key,
                'label' => $label,
                'value' => (int) ($counts[$key] ?? 0),
            ])
            ->sortByDesc('value')
            ->values()->all();

        // summed from the bars, like byStage(): the header cannot then disagree
        // with the chart underneath it
        return ['total' => array_sum(array_column($bars, 'value')), 'bars' => $bars];
    }

    /* ---------------- helpers ---------------- */

    /**
     * One follow-up panel: the first few rows plus the real total, so the
     * header count and the "View all" link are not capped at what is shown.
     *
     * Never filtered by the dashboard date range — like the overdue KPI card,
     * these two panels are about right now.
     *
     * "Today" is today in IST: the app timezone is Asia/Kolkata and
     * scheduled_at is a plain DATETIME, so the stored value and both scopes
     * are already IST wall-clock. Nothing is converted on the way in or out.
     *
     * Ascending scheduled_at reads correctly for both: earliest first today,
     * oldest — the most neglected lead — first when overdue.
     */
    private function followUps($user, string $scope): array
    {
        // hasLead() sits inside the closure, so the rows and the total it is
        // compared against are counted the same way
        $query = fn() => $scope === 'overdue'
            ? Todo::forUser($user)->hasLead()->overdue()
            : Todo::forUser($user)->hasLead()->dueToday();

        return [
            'rows' => $query()
                ->with([
                    /*
                     | days_in_stage is appended; see TodoController::index().
                     |
                     | No project any more: the panel rows were restructured to
                     | two aligned lines and the project name is not one of the
                     | things on them, so loading the relation for every row was
                     | a query nothing read.
                     */
                    'lead:id,first_name,middle_name,last_name,mobile_number,stage,stage_changed_at,created_at,not_connected_count',
                    // role too: the panel prints "name · role" under the lead
                    'owner:id,first_name,last_name,role',
                ])
                ->orderBy('scheduled_at')
                ->limit(self::PANEL_ROWS)
                ->get()
                // the panels open the same CompleteTaskModal the To-do page does
                ->each(fn($todo) => $todo->setAttribute(
                    'follow_up_previews',
                    $this->scheduler->previews($todo->lead)
                ))
                ->all(),
            'total' => $query()->count(),
        ];
    }

    /* ---------------- the sign-in notice ---------------- */

    /**
     * What is owed today, shown once per session on the first dashboard load
     * after signing in.
     *
     * Null means render nothing at all — either this session has already been
     * told, or there is nothing to tell. An empty notice is worse than no
     * notice: it trains people to close the thing without reading it.
     *
     * The population is exactly the Pending follow-ups card: everything still
     * open that was due today or earlier. Yesterday's uncalled lead is the one
     * this notice most needs to surface, so the cut is `<= end of today`
     * rather than the Today tab's `= today`, and the wording on the panel says
     * "due today or earlier" so the number is never a mystery.
     *
     * Role scoping is scopeForUser() and nothing else: an admin gets the whole
     * team's and the breakdown by assignee that goes with it, a telecaller or
     * salesperson gets their own and no breakdown, because every row would
     * carry their own name. The front end never filters — it is never sent
     * anything it should not see.
     */
    private function todayDigest(Request $request): ?array
    {
        $user = $request->user();

        if ($request->session()->get(self::DIGEST_SEEN)) {
            return null;
        }

        // a factory, not a builder: the count, the grouping and the rows are
        // three queries that have to be asking the same question
        $due = fn() => Todo::forUser($user)->hasLead()->pending()
            ->where('scheduled_at', '<=', today()->endOfDay());

        $total = $due()->count();

        if ($total === 0) {
            /*
             | Nothing owed, so nothing shown — and the session is left unmarked
             | on purpose. "Once per session" is about the notice, not about the
             | check: a user who signs in to an empty list at nine should still
             | be told about the task that lands at ten.
             */
            return null;
        }

        $request->session()->put(self::DIGEST_SEEN, true);

        $rows = $due()
            ->with([
                // only what the flat rows below read: the notice builds its own
                // arrays, so nothing appended by the model is serialised and
                // the wide select the panels need is not needed here
                'lead:id,first_name,middle_name,last_name,mobile_number,stage',
                'owner:id,first_name,last_name',
            ])
            ->orderBy('scheduled_at')
            ->limit(self::DIGEST_ROWS)
            ->get();

        $startOfToday = today()->startOfDay();

        return [
            'total'  => $total,
            'groups' => $this->digestGroups($user, $due),
            'rows'   => $rows->map(fn(Todo $todo) => [
                'id'       => $todo->id,
                'name'     => $todo->lead?->full_name,
                'mobile'   => $todo->lead?->mobile_number,
                'stage'    => $todo->lead?->stage,
                'at'       => $todo->scheduled_at?->toIso8601String(),
                // the row decides between a clock and a date from this, the way
                // the two panels below do
                'overdue'  => $todo->scheduled_at !== null
                    && $todo->scheduled_at->lt($startOfToday),
                'owner'    => $todo->owner?->display_name,
            ])->all(),
        ];
    }

    /**
     * "Priya has 3, Amit has 4" — admin only.
     *
     * Counted over the whole population, not over the handful of rows listed,
     * so the breakdown adds up to the number in the heading. Anyone else is
     * looking at a list that is entirely their own, so there is nothing to
     * break down and an empty array turns the line off.
     *
     * @param  callable(): \Illuminate\Database\Eloquent\Builder  $due
     * @return list<array{name: string, count: int}>
     */
    private function digestGroups(User $user, callable $due): array
    {
        if (! $user->isAdmin()) {
            return [];
        }

        $counts = $due()
            ->selectRaw('assigned_to, count(*) as total')
            ->groupBy('assigned_to')
            ->pluck('total', 'assigned_to');

        // first names: this is a one-line summary, and the full list underneath
        // carries the full name against each row
        $names = User::whereIn('id', $counts->keys())
            ->pluck('first_name', 'id');

        return $counts
            ->map(fn($n, $id) => [
                'name'  => $names[$id] ?? 'Unassigned',
                'count' => (int) $n,
            ])
            ->sortByDesc('count')
            ->values()->all();
    }
}
