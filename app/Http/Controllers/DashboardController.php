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
     * Rows listed in the sign-in modal. It is a nudge, not a work queue — the
     * count in its heading is the real total, anything past this is summed up
     * as "and N more", and the button in the footer goes to the page that
     * holds all of them.
     *
     * Ten is what fits a phone's modal body without the list becoming a page
     * of its own. Modal.vue caps the panel at 94vh and scrolls the body, so
     * the number is about how much is worth reading, not about overflow.
     */
    private const DIGEST_ROWS = 10;

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
             | The first two are the same query — stagesByLead(), leads grouped
             | by the stage each one is at now — asked twice, once without a
             | window and once with the selected one. That is the only
             | difference between them, and it is deliberate: read side by side
             | they say where the whole book of enquiries stands and which part
             | of it arrived in this period.
             |
             | Two of the four ignore the picker, and for the one reason
             | anything here is allowed to: they describe a standing total
             | rather than a period. The notes on both cards say so.
             */
            'charts'  => fn() => [
                // every lead, grouped by leads.stage. No window, ever.
                'stagesAllTime'  => $this->stagesByLead($user),
                // the same query, narrowed to leads.created_at in the range
                'stagesInPeriod' => $this->stagesByLead($user, $from, $to),
                // leads.created_at again, split by where they came from
                'bySource'       => $this->bySource($user, $from, $to),
                // the other exception: work outstanding right now
                'byTodoType'     => $this->byTodoType($user),
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
         | The three event cards all come out of stageEvents() — one query,
         | read three times.
         |
         | They used to be three separate queries that merely looked alike, and
         | "these must stay identical" is not something a reader can check or a
         | compiler can enforce.
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
     * Leads grouped by the stage each one is at now — the query behind BOTH
     * stage charts, and the only one either of them runs.
     *
     * Called with no window it is "Where all enquiries stand": every lead this
     * user can see, at whatever stage it has reached, however long ago it came
     * in. Called with one it is "Enquiries in this period": the same census
     * narrowed to the leads that arrived between the two dates.
     *
     * One method rather than two, because the two charts differ in exactly one
     * thing — whether there is a whereBetween — and a difference that small is
     * one a reader has to be able to see at a glance rather than diff by eye.
     * Written out twice they drifted: the second copy went off to `todos` and
     * started counting stage transitions instead of leads, which is a different
     * question with a different answer, and a Fresh lead created today (no
     * completed to-do, so no transition, so no row) fell out of it entirely
     * while sitting plainly in the first. Same table, same column, same
     * grouping, same zero-fill, one window apart.
     *
     * Soft-deleted leads are excluded by the model's own scope; visibleTo()
     * keeps a user to the leads that are theirs to see.
     *
     * @param  ?Carbon  $from  null for all time — both bounds or neither
     * @return array{total: int, bars: list<array{key: string, label: string, value: int, color: string}>}
     */
    private function stagesByLead($user, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $counts = Lead::visibleTo($user)
            ->when(
                $from !== null && $to !== null,
                fn($q) => $q->whereBetween('created_at', [$from, $to]),
            )
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
         | the count of leads in the population — and for the windowed call that
         | is the New enquiries card, out of the same population. Taking it from
         | the bars means a header can never disagree with the chart underneath
         | it, which is the whole failure this shape is here to stop repeating.
         */
        return ['total' => array_sum(array_column($bars, 'value')), 'bars' => $bars];
    }

    /**
     * Every stage transition inside the range, counted once per lead per stage.
     *
     * The single source for the three event cards — Site visits done, Bookings
     * and Lost. One grouped query rather than three near-identical ones: three
     * queries that must agree is a promise, one query read three times is a
     * fact.
     *
     * Nothing on the page draws this. It answers "what happened during the
     * range", which is a question about `todos` and about time; the two stage
     * charts answer "where do the leads stand", which is a question about
     * `leads` and about stage. Mixing the two is what put a transition count
     * under a chart titled for enquiries — see stagesByLead().
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
     * The work still waiting, split by the kind of work it is. One of the two
     * charts no range can move — the all-time stage census is the other.
     *
     * It ignores the picker for the single reason anything on this page is
     * allowed to — it describes right now rather than a period. A pending
     * to-do is a thing that has not happened yet, so "pending to-dos created
     * last week" answers nothing anyone asks. The card and the note both say
     * so on the page.
     *
     * Same window as the Calls pending card, and deliberately the same
     * expression: everything pending that was due on or before today. The card
     * had that bound and the chart did not, so two to-dos scheduled for
     * tomorrow sat in the chart's total while the card above it counted only
     * one — a card and a chart disagreeing about the same rows, which is the
     * exact failure the rest of this class is arranged to prevent.
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
            ->where('scheduled_at', '<=', today()->endOfDay())
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
        // with the chart underneath it — and the sum is the Calls pending card,
        // out of the same window
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
     * told, or there is nothing to tell. An empty modal is worse than no
     * modal: it trains people to close the thing without reading it.
     *
     * The population is exactly the Calls pending card: everything still open
     * that was due today or earlier, in one list. Not two — there is no
     * separate late bucket here and there must not be one. A row from an
     * earlier day is the same kind of thing as a row from this morning; all
     * that changes is that its timestamp carries a date, so the reader is not
     * shown "10:30 AM" for something from last Tuesday.
     *
     * Role scoping is scopeForUser() and nothing else. An admin is sent the
     * whole team's, grouped by the person it belongs to; a telecaller or
     * salesperson is sent their own in a single unnamed group. The front end
     * never filters, because it is never sent anything it should not see.
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
                // only what digestRow() reads: the modal builds its own arrays,
                // so nothing appended by the model is serialised and the wide
                // select the panels need is not needed here
                'lead:id,first_name,middle_name,last_name,mobile_number,stage',
            ])
            ->orderBy('scheduled_at')
            ->limit(self::DIGEST_ROWS)
            ->get();

        return [
            'total'  => $total,
            'shown'  => $rows->count(),
            // "and 4 more" — the difference between what is listed and what is
            // owed, computed here so the modal never has to subtract anything
            'more'   => $total - $rows->count(),
            'groups' => $this->digestGroups($user, $due, $rows),
        ];
    }

    /**
     * The listed rows, under the person they belong to.
     *
     * Grouping is the admin's view and only the admin's: everyone else is
     * looking at a list that is entirely their own, so it comes back as one
     * group with a null name and the modal draws no heading for it. That keeps
     * one shape on the wire instead of two, and the "is this mine or the
     * team's" decision stays here rather than being re-derived in the Vue.
     *
     * The per-person count is that person's whole workload, not the number of
     * their rows that made the cut — "Priya Shah · 18" beside six listed rows
     * is the useful reading, and the modal's "and N more" accounts for the
     * rest. Groups are built from the listed rows, so nobody appears as a
     * heading with nothing under it.
     *
     * @param  callable(): \Illuminate\Database\Eloquent\Builder  $due
     * @param  \Illuminate\Database\Eloquent\Collection<int, Todo>  $rows
     * @return list<array{name: ?string, count: int, rows: list<array<string, mixed>>}>
     */
    private function digestGroups(User $user, callable $due, $rows): array
    {
        if (! $user->isAdmin()) {
            return [[
                'name'  => null,
                'count' => $rows->count(),
                'rows'  => $rows->map(fn(Todo $todo) => $this->digestRow($todo))->all(),
            ]];
        }

        $counts = $due()
            ->selectRaw('assigned_to, count(*) as total')
            ->groupBy('assigned_to')
            ->pluck('total', 'assigned_to');

        $names = User::whereIn('id', $counts->keys())
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        /*
         | groupBy keeps first-appearance order and the rows arrive sorted by
         | scheduled_at, so the person with the oldest outstanding call heads
         | the list. Sorting the groups by size instead would bury a single
         | forgotten call from last week under somebody's busy afternoon.
         */
        return $rows->groupBy('assigned_to')
            ->map(fn($group, $id) => [
                'name'  => $names[$id]?->display_name ?? 'Unassigned',
                'count' => (int) ($counts[$id] ?? $group->count()),
                'rows'  => $group->map(fn(Todo $todo) => $this->digestRow($todo))->values()->all(),
            ])
            ->values()->all();
    }

    /**
     * One listed row: who to call, on what number, when, and where the lead
     * stands.
     *
     * `earlier` says only whether the timestamp needs its date printed. It is
     * not a status and the modal draws no second bucket from it — a call from
     * yesterday sits in the same list as one from this morning, because that
     * is what "due today or earlier" means.
     *
     * @return array<string, mixed>
     */
    private function digestRow(Todo $todo): array
    {
        return [
            'id'      => $todo->id,
            'name'    => $todo->lead?->full_name,
            'mobile'  => $todo->lead?->mobile_number,
            'stage'   => $todo->lead?->stage,
            'at'      => $todo->scheduled_at?->toIso8601String(),
            'earlier' => $todo->scheduled_at !== null
                && $todo->scheduled_at->lt(today()->startOfDay()),
        ];
    }
}
