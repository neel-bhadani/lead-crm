<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesFilters;
use App\Models\Lead;
use App\Models\Todo;
use App\Models\User;
use App\Support\CrmTaxonomy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * The three dimensions a click on the page can filter by, in the order the
     * chips are drawn.
     *
     *   stage    where a lead stands now      — the two stage charts
     *   source   where a lead came from       — the doughnut
     *   reached  a stage a lead has been through — the funnel
     *
     * They are three keys and not one, because they are three different
     * questions about the same lead and a reader can hold all three at once:
     * "Facebook leads, standing at In discussion, that have had a site visit"
     * is a sentence a builder says out loud.
     *
     * A selection applies to EVERY query on the page, the visual it was
     * clicked on included. That is the rule with no exceptions, and the reason
     * for it is reconciliation: a tile that quietly excused itself from the
     * filter would be a tile whose number no longer answers the same question
     * as the tile beside it. Clicking the live segment again removes it, and
     * the chips under the date control remove any of them from anywhere.
     */
    private const CROSS_KEYS = ['stage', 'source', 'reached'];

    /**
     * The stages the funnel does NOT draw a band for, even when they are active.
     *
     * A funnel is a progression, and three of the nine stages are not steps
     * along it: Not connected is a failed attempt, Site visit scheduled is an
     * appointment rather than an outcome, and Lost is where a lead leaves the
     * funnel rather than a narrower part of it. Every other stage — including
     * one the admin adds tomorrow — gets a band.
     *
     * A DENY LIST RATHER THAN THE OLD SIX-KEY ALLOW LIST, and the difference is
     * the point of moving the vocabulary into a table. The allow list froze both
     * the membership and the ORDER of the funnel in PHP: an admin who dragged
     * Details shared above Connected changed every other chart on the page and
     * not this one, which is exactly the kind of quiet disagreement the two
     * stage charts were rebuilt to stop. Membership is still an editorial call,
     * so it stays in code; the order is now the admin's, read from
     * `lead_stages.sort_order` like everything else.
     */
    private const NON_FUNNEL_STAGES = ['not_connected', 'site_visit_scheduled', 'lost'];

    /**
     * A range of this many days or fewer is bucketed a day at a time; anything
     * longer goes to weeks. Thirty-one rather than a round thirty so that the
     * Last 30 days preset — and a calendar month typed into the custom picker
     * — both stay daily, which is what a reader of that preset expects.
     */
    private const DAILY_MAX_DAYS = 31;

    /**
     * stageEvents() for this request, computed once.
     *
     * `cards` and `charts` are separate Inertia closures and both need it —
     * the three event cards on one side, the funnel on the other — so without
     * this the same grouped query would run twice on a full page load. One
     * request has one user, one window and one cross-filter, so there is
     * nothing for a key to distinguish.
     *
     * Cleared at the top of index(), and that is not belt and braces. A route
     * holds on to the controller it resolved, so ONE instance of this class
     * serves every request the process handles: left uncleared this would
     * answer the second request with the first one's counts, and would go on
     * doing it after a lead was deleted in between. Per-request state on a
     * controller has to be reset per request.
     *
     * @var ?array<string, int>
     */
    private ?array $stageEvents = null;

    public function index(Request $request)
    {
        // the router keeps this controller instance between requests; see the
        // note on the property
        $this->stageEvents = null;

        $user = $request->user();
        $filters = $this->filters($request);
        $range = $this->resolveRange($filters);
        $from = $range['from'];
        $to = $range['to'];

        /*
         | The cross-filter, and the whole of it: at most one stage, one source
         | and one reached-stage, each already validated against config by
         | filters(). Empty is the ordinary dashboard — every method below adds
         | no clause at all for an empty cross-filter, so the unfiltered page
         | runs exactly the SQL it always ran.
         */
        $cross = $this->crossFilters($filters);

        return Inertia::render('Dashboard', [
            'range' => [
                'key' => $range['key'],
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $range['label'],
                // today in IST. The picker uses this as its max rather than the
                // browser clock, which may be in another timezone entirely.
                'today' => today()->toDateString(),
                'maxSpanDays' => self::MAX_SPAN_DAYS,
            ],
            /*
             | Closures, not values. Logging a call from the dashboard reloads
             | only `cards` and `followUps`; a plain array would still run every
             | chart query and then have it thrown away by the partial filter.
             | Inertia only invokes a closure for a prop it is actually sending.
             */
            'cards' => fn () => $this->cards($user, $from, $to, $cross),
            /*
             | Four charts, in the order the page draws them.
             |
             | The first two are the same query — stagesByLead(), leads grouped
             | by the stage each one is at now — asked twice, once without a
             | window and once with the selected one. That is the only
             | difference between them, and it is deliberate: read one against
             | the other they say where the whole book of enquiries stands and
             | which part of it arrived in this period.
             |
             | One of the three ignores the picker, and for the one reason
             | anything here is allowed to: it describes a standing total rather
             | than a period. The note on that card says so.
             */
            'charts' => fn () => [
                // every lead, grouped by leads.stage. No window, ever.
                'stagesAllTime' => $this->stagesByLead($user, null, null, $cross),
                // the same query, narrowed to leads.created_at in the range
                'stagesInPeriod' => $this->stagesByLead($user, $from, $to, $cross),
                // leads.created_at again, split by where they came from
                'bySource' => $this->bySource($user, $from, $to, $cross),
                /*
                 | The funnel, and it draws nothing of its own: it is
                 | stageEvents() — the query behind the Site visits, Bookings
                 | and Lost cards — read for six of its nine keys. That is why
                 | the Site visit done and Booking done bands are the matching
                 | cards rather than merely agreeing with them.
                 */
                'funnel' => $this->funnel($user, $from, $to, $cross),
            ],
            /*
             | The sign-in notice. A closure for the same reason the rest are,
             | and for one more: invoking it is what marks the session as told,
             | and Inertia only invokes a closure for a prop it is actually
             | sending. Reloading `cards` after a logged call therefore cannot
             | burn the one showing this session was owed.
             */
            'todayDigest' => fn () => $this->todayDigest($request),
            // both panels are "right now", never filtered by the date range
            'followUps' => fn () => [
                'today' => $this->followUps($user, 'today', $cross),
                'overdue' => $this->followUps($user, 'overdue', $cross),
            ],
            /*
             | The cross-filter as the page holds it: three keys, empty string
             | for the ones that are off.
             |
             | A plain value rather than a closure. Logging a call reloads
             | `cards`, `charts` and `followUps` and cannot change which
             | segments are selected, so there is nothing here for a partial
             | reload to refresh — and the chips are built from these three
             | strings plus `options`, which the page already has.
             */
            'filters' => [
                'stage' => $cross['stage'] ?? '',
                'source' => $cross['source'] ?? '',
                'reached' => $cross['reached'] ?? '',
            ],
            'options' => [
                // the three presets DateRangePicker draws, shared with the two
                // report pages so all three offer the same windows
                'ranges' => config('crm.date_ranges'),
                /*
                 | `stages` and `sources` are EVERY row, retired ones included,
                 | because these two maps are what StageBadge, the cross-filter
                 | chips and the history list look labels up in — a lead sitting
                 | in a stage the admin switched off yesterday must still read
                 | "In discussion" and not `in_discussion`. What may be CHOSEN
                 | is the two lists below them, which the dropdowns filter by.
                 */
                'stages' => CrmTaxonomy::allStages(),
                'activeStages' => CrmTaxonomy::activeStageKeys(),
                'stageColors' => CrmTaxonomy::stageColors(),
                'sources' => CrmTaxonomy::allSources(),
                'activeSources' => CrmTaxonomy::activeSourceKeys(),
                // CompleteTaskModal needs these to offer a reason when a call
                // ends in "lost"; StageBadge inside it reads stageColors above.
                'reasons' => config('crm.lost_reasons'),
                // and these to book the next follow-up: the task types it can
                // be, the stages that end the chain instead, and the one stage
                // that forces the next task to be the site visit
                'types' => config('crm.todo_types'),
                'terminalStages' => CrmTaxonomy::terminalStages(),
                'handoverStage' => CrmTaxonomy::handoverStage(),
                'handoverRole' => CrmTaxonomy::ownerRoleFor(CrmTaxonomy::handoverStage()),
                // CallButtons builds its tel: and wa.me hrefs from this
                'countryCode' => config('crm.country_code'),
                'roleLabels' => config('crm.role_labels'),
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
        /*
         | The three cross-filter keys are validated against config, and that
         | is the whole of their sanitising. They reach a query builder as
         | column VALUES — `where('stage', $stage)` — and the session they are
         | read back out of is user-controlled, so a value that is not one of
         | the configured keys must never get that far. `in:` is what makes the
         | list of allowed values and the list the chips are drawn from the
         | same list.
         */
        /*
         | EVERY key, not only the active ones. These three are read-only
         | filters over data that already exists: a lead filed under a source
         | that has since been retired is still a lead somebody may want to
         | narrow to, and the chip that offers it comes from the same list.
         | Only the two WRITE forms — LeadRequest and CompleteTodoRequest —
         | restrict a stage to the active ones.
         */
        $stages = implode(',', CrmTaxonomy::stageKeys());
        $sources = implode(',', CrmTaxonomy::sourceKeys());

        return $this->resolveFilters(
            $request,
            'dashboard',
            [
                'range' => ['sometimes', 'string', 'in:today,7,30'],
                'from' => ['sometimes', 'string', 'date_format:Y-m-d'],
                'to' => ['sometimes', 'string', 'date_format:Y-m-d'],
                // where a lead stands now — the two stage charts' own dimension
                'stage' => ['sometimes', 'string', 'in:'.$stages],
                // where it came from — the doughnut's dimension
                'source' => ['sometimes', 'string', 'in:'.$sources],
                // a stage a lead has BEEN through — the funnel's dimension
                'reached' => ['sometimes', 'string', 'in:'.$stages],
            ],
            ['range' => '30'],
            fn (array $state) => $this->sanitiseRange($state),
        );
    }

    /* ---------------- cross-filter ---------------- */

    /**
     * The cross-filter, lifted out of the resolved filters and stripped of the
     * keys that are off.
     *
     * An empty array is the ordinary unfiltered dashboard, and every method
     * that takes one checks for exactly that before it touches a query. That
     * is the promise this page is built on: with nothing selected, not one
     * clause is added anywhere, so every card and every chart runs the SQL it
     * ran before cross-filtering existed and returns the number it returned.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function crossFilters(array $filters): array
    {
        $cross = array_intersect_key($filters, array_flip(self::CROSS_KEYS));

        return array_filter($cross, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * The cross-filter as clauses on a LEADS query.
     *
     * Additional `where`s on the query that was going to run anyway — never a
     * second query, never a different one. `stage` and `source` are columns on
     * `leads`; `reached` is not a column at all, it is "this lead has been
     * through that stage", which is the same completed-to-do history the event
     * cards count, asked as an existence test rather than as a count.
     *
     * Returns the builder untouched when nothing is selected, which is what
     * keeps the unfiltered page byte-identical.
     *
     * @param  array<string, string>  $cross
     */
    private function crossFilterLeads($query, array $cross)
    {
        if (! $cross) {
            return $query;
        }

        return $query
            ->when(isset($cross['stage']), fn ($q) => $q->where('stage', $cross['stage']))
            ->when(isset($cross['source']), fn ($q) => $q->where('source', $cross['source']))
            /*
             | whereNotNull('completed_at') for the same reason every other
             | history clause on this page carries it: only a completed row is
             | a thing that happened, and a pending to-do carrying a planned
             | outcome is not a stage the lead has reached.
             */
            ->when(isset($cross['reached']), fn ($q) => $q->whereHas(
                'todos',
                fn ($t) => $t->where('outcome_stage', $cross['reached'])->whereNotNull('completed_at'),
            ));
    }

    /**
     * The same cross-filter as clauses on a TODOS query, reached through the
     * lead the to-do belongs to.
     *
     * A second `whereHas('lead')` beside the one `hasLead()` already adds,
     * rather than an argument to that scope: the scope is shared with the
     * To-do page and a page's own filter is not its business. Two whereHas on
     * one relation AND together, which is what "this to-do's lead is also in
     * the filtered population" means.
     *
     * @param  array<string, string>  $cross
     */
    private function crossFilterTodos($query, array $cross)
    {
        if (! $cross) {
            return $query;
        }

        return $query->whereHas('lead', fn ($q) => $this->crossFilterLeads($q, $cross));
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
        $to = $state['to'] ?? null;

        if ($from === null || $to === null) {
            unset($state['from'], $state['to']);

            return $state;
        }

        $today = today();   // IST — the app timezone is Asia/Kolkata
        $start = Carbon::createFromFormat('Y-m-d', $from)->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $to)->endOfDay();

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
        $to = $filters['to'] ?? null;

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
            '7' => $this->describe('7', $today->copy()->subDays(6)->startOfDay(), $today->copy()->endOfDay()),
            default => $this->describe('30', $today->copy()->subDays(29)->startOfDay(), $today->copy()->endOfDay()),
        };
    }

    private function describe(string $key, Carbon $from, Carbon $to): array
    {
        return [
            'key' => $key,
            'from' => $from,
            'to' => $to,
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

        return $from->format($format).' – '.$to->format($format);
    }

    /* ---------------- KPI cards ---------------- */

    private function cards($user, Carbon $from, Carbon $to, array $cross = []): array
    {
        // intake: these two really are about when the lead arrived
        $total = $this->crossFilterLeads(Lead::visibleTo($user), $cross)
            ->whereBetween('created_at', [$from, $to])->count();
        $today = $this->crossFilterLeads(Lead::visibleTo($user), $cross)
            ->whereDate('created_at', today())->count();

        /*
         | The three event cards all come out of stageEvents() — one query,
         | read three times.
         |
         | They used to be three separate queries that merely looked alike, and
         | "these must stay identical" is not something a reader can check or a
         | compiler can enforce.
         */
        $events = $this->stageEvents($user, $from, $to, $cross);

        $visits = $events['site_visit_done'];
        $booked = $events['booking_done'];
        $lost = $events['lost'];

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
        $cohortBooked = $this->crossFilterLeads(Lead::visibleTo($user), $cross)
            ->whereBetween('created_at', [$from, $to])
            // whereNotNull('completed_at') for the same reason the cards use it:
            // the numerator has to count a booking that *happened*, and only a
            // completed row is a thing that happened
            ->whereHas('todos', fn ($q) => $q->where('outcome_stage', 'booking_done')
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
        $pending = $this->crossFilterTodos(Todo::forUser($user)->hasLead()->pending(), $cross)
            ->where('scheduled_at', '<=', today()->endOfDay())
            ->count();

        /**
         * The change indicator compares the period immediately before this one,
         * of the same length and ending the day before it starts: 1–15 June is
         * measured against 17–31 May, and Today against the whole of yesterday.
         */
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->startOfDay()->subDays($this->spanInDays($from, $to) - 1);

        $prevTotal = $this->crossFilterLeads(Lead::visibleTo($user), $cross)
            ->whereBetween('created_at', [$prevFrom, $prevTo])
            ->count();

        return [
            'total' => $total,
            'today' => $today,
            'visits' => $visits,
            'booked' => $booked,
            'lost' => $lost,
            'pending' => $pending,
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
            'delta' => $prevTotal > 0 ? round(($total - $prevTotal) / $prevTotal * 100) : null,
            /*
             | One short series per tile, for the sparkline inside it. Shapes,
             | not figures: no axis is drawn beside them and no number is read
             | off them, so what matters is that a bucket with nothing in it is
             | a zero and not a missing point.
             */
            'spark' => $this->sparklines($user, $from, $to, $cross),
        ];
    }

    /* ---------------- sparklines ---------------- */

    /**
     * The six KPI series, bucketed across the selected range.
     *
     * Three queries for six lines, and each of the three is the card's own
     * query with a date grouping added — never a different population. The
     * event series come out of one grouped query read three times, exactly as
     * the three event cards do.
     *
     * Every series is zero-filled across every bucket. A day with nothing in
     * it draws a point on the floor; it never draws a gap, and it never
     * shortens the line. That is the same mistake this page has already fixed
     * once, and a sparkline is where it would be hardest to see.
     *
     * @return array{weekly: bool, buckets: int, total: list<int>, today: list<int>,
     *               visits: list<int>, booked: list<int>, lost: list<int>, pending: list<int>}
     */
    private function sparklines($user, Carbon $from, Carbon $to, array $cross): array
    {
        $bucket = $this->bucketing($from, $to);

        // 1. intake — leads.created_at, the New enquiries card grouped by day
        $intake = $this->crossFilterLeads(Lead::visibleTo($user), $cross)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as d, count(*) as total')
            ->groupBy('d')
            ->pluck('total', 'd')
            ->all();

        /*
         | 2. the three event series, from one query — stageEvents() with a day
         | added to the grouping. COUNT(DISTINCT lead_id) per day per stage, so
         | a lead that visited twice in one day is one point that day; across
         | two days it is a point on each, which is what a trend line of "how
         | much happened when" should show.
         */
        $events = [];

        $rows = Todo::whereHas('lead', fn ($q) => $this->crossFilterLeads($q->visibleTo($user), $cross))
            ->whereIn('outcome_stage', ['site_visit_done', 'booking_done', 'lost'])
            ->whereBetween('completed_at', [$from, $to])
            ->selectRaw('DATE(completed_at) as d, outcome_stage as s, count(distinct lead_id) as total')
            ->groupBy('d', 's')
            ->get();

        foreach ($rows as $row) {
            $events[$row->s][$row->d] = (int) $row->total;
        }

        /*
         | 3. the outstanding work, by the day it was due.
         |
         | The Calls pending card is a stock and no date range moves it, so
         | this is the one series whose card total is not the sum of its own
         | line: it is that same population — everything still open that was
         | due today or earlier — clipped to the selected range and grouped by
         | the day it was due. The shape says when the backlog piled up.
         */
        $pending = $this->crossFilterTodos(Todo::forUser($user)->hasLead()->pending(), $cross)
            ->where('scheduled_at', '<=', today()->endOfDay())
            ->whereBetween('scheduled_at', [$from, $to])
            ->selectRaw('DATE(scheduled_at) as d, count(*) as total')
            ->groupBy('d')
            ->pluck('total', 'd')
            ->all();

        return [
            'weekly' => $bucket['weekly'],
            'buckets' => $bucket['count'],
            'total' => $this->series($bucket, $intake),
            /*
             | Enquiries today rides the intake series too, and deliberately.
             | It is the same measurement — leads by the day they arrived — and
             | the card is one bucket of it, so drawing a second line would be
             | drawing the same line under a different name.
             */
            'today' => $this->series($bucket, $intake),
            'visits' => $this->series($bucket, $events['site_visit_done'] ?? []),
            'booked' => $this->series($bucket, $events['booking_done'] ?? []),
            'lost' => $this->series($bucket, $events['lost'] ?? []),
            'pending' => $this->series($bucket, $pending),
        ];
    }

    /**
     * How the range is cut up, and which bucket each date in it falls in.
     *
     * Daily under 32 days, weekly beyond — a two-year range at one point a day
     * is 731 points in a 60px box, which is a smudge rather than a shape. The
     * weeks run forward from the first day of the range rather than from a
     * Monday, so the first bucket is always a whole one and the range's own
     * start is where the line starts.
     *
     * The map is built by walking the range a day at a time rather than by
     * arithmetic on the dates that came back from the database, so every day
     * in the window has a bucket whether or not anything happened on it. That
     * is where the zero-filling actually comes from.
     *
     * @return array{count: int, weekly: bool, map: array<string, int>}
     */
    private function bucketing(Carbon $from, Carbon $to): array
    {
        $days = $this->spanInDays($from, $to);
        $weekly = $days > self::DAILY_MAX_DAYS;
        $size = $weekly ? 7 : 1;

        $map = [];
        $cursor = $from->copy()->startOfDay();

        for ($day = 0; $day < $days; $day++) {
            $map[$cursor->toDateString()] = intdiv($day, $size);
            $cursor->addDay();
        }

        return ['count' => (int) ceil($days / $size), 'weekly' => $weekly, 'map' => $map];
    }

    /**
     * One series: a count for every bucket, in order, zeros included.
     *
     * A date the database returned that is not in the map cannot happen — the
     * query was windowed to the same range the map was built from — but it is
     * dropped rather than trusted, because the alternative is a stray key
     * appending a point past the end of the line.
     *
     * @param  array{count: int, weekly: bool, map: array<string, int>}  $bucket
     * @param  array<string, int|string>  $byDate
     * @return list<int>
     */
    private function series(array $bucket, array $byDate): array
    {
        $out = array_fill(0, $bucket['count'], 0);

        foreach ($byDate as $date => $count) {
            $index = $bucket['map'][(string) $date] ?? null;

            if ($index !== null) {
                $out[$index] += (int) $count;
            }
        }

        return $out;
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
     * The cross-filter narrows both of them equally, the "no window" one
     * included: "where all enquiries stand" is a census of a population, and
     * when the reader has picked a population it is that one. With nothing
     * selected not a clause is added and the pair is exactly what it was.
     *
     * @param  ?Carbon  $from  null for all time — both bounds or neither
     * @return array{total: int, bars: list<array{key: string, label: string, value: int, color: string}>}
     */
    private function stagesByLead($user, ?Carbon $from = null, ?Carbon $to = null, array $cross = []): array
    {
        $counts = $this->crossFilterLeads(Lead::visibleTo($user), $cross)
            ->when(
                $from !== null && $to !== null,
                fn ($q) => $q->whereBetween('created_at', [$from, $to]),
            )
            ->selectRaw('stage, count(*) as total')
            ->groupBy('stage')
            ->pluck('total', 'stage');

        /*
         | Zero-fill — a missing bar looks like a bug, a zero bar looks like
         | information.
         |
         | The active stages in the admin's order, PLUS any retired stage that
         | leads are actually standing in. The second half is not tidiness: the
         | total below is summed from these bars, so a stage dropped from the
         | axis while thirty leads sit in it would not draw a shorter chart, it
         | would draw one whose header is thirty short of the truth.
         */
        $colors = CrmTaxonomy::stageColors();

        $bars = collect(CrmTaxonomy::stageUniverse($counts->keys()))
            ->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'value' => (int) ($counts[$key] ?? 0),
                'color' => $colors[$key] ?? null,
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
     * The funnel is drawn from this and from nothing else — six of the nine
     * keys, in progression order. It answers "what happened during the range",
     * which is a question about `todos` and about time; the two stage charts
     * answer "where do the leads stand", which is a question about `leads` and
     * about stage. Mixing the two is what put a transition count under a chart
     * titled for enquiries — see stagesByLead().
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
    private function stageEvents($user, Carbon $from, Carbon $to, array $cross = []): array
    {
        if ($this->stageEvents !== null) {
            return $this->stageEvents;
        }

        $counts = Todo::whereHas('lead', fn ($q) => $this->crossFilterLeads($q->visibleTo($user), $cross))
            ->whereNotNull('outcome_stage')
            ->whereBetween('completed_at', [$from, $to])
            ->selectRaw('outcome_stage, count(distinct lead_id) as total')
            ->groupBy('outcome_stage')
            ->pluck('total', 'outcome_stage');

        // every stage the vocabulary knows, so the funnel below can index
        // this by key without checking first
        return $this->stageEvents = collect(CrmTaxonomy::allStages())
            ->map(fn ($label, $key) => (int) ($counts[$key] ?? 0))
            ->all();
    }

    /**
     * The funnel: six of stageEvents()'s nine keys, in progression order, each
     * with the drop from the band above it.
     *
     * There is no query here and there must not be one. The counts are the
     * ones the three event cards are read out of — todos.outcome_stage,
     * completed_at inside the range, COUNT(DISTINCT lead_id) — so the Site
     * visit done band and the Site visits card are not two figures that agree,
     * they are one figure printed twice. Site visits and Bookings are two of
     * the six bands, which is what makes the funnel reconcile with the strip
     * above it by construction rather than by care.
     *
     * Two things a reader should know about what this is NOT. It is not a
     * cohort: a lead that connected last month and booked this one is in the
     * Booking done band and not in the Connected one, because the window is on
     * when each event happened. And `fresh` is not a transition — arriving is
     * not something anybody logs, so nothing ever writes it to outcome_stage
     * and the top band reads zero. The band is drawn anyway rather than
     * dropped: a funnel that starts at Connected reads as though Fresh were
     * missing from the data, where a zero band reads as what it is.
     *
     * @return array{bands: list<array{key: string, label: string, value: int,
     *                color: string, width: float, drop: ?float}>, total: int}
     */
    /**
     * The funnel's bands, top to bottom: the active stages in the admin's own
     * order, less the three that are not steps along the journey.
     *
     * Active only, and no `$present` net like the stage charts have. The funnel
     * counts TRANSITIONS inside a range rather than where leads stand, and a
     * retired stage is one nothing can transition into any more — the band
     * would read zero for every range from here on, which is a row of noise
     * rather than the piece of information a zero bar is on the pipeline chart.
     * The counts it is drawn from are unchanged either way: stageEvents() is
     * keyed over every stage, and the three event cards read that, not this.
     *
     * @return list<string>
     */
    private function funnelStages(): array
    {
        return array_values(array_diff(
            array_keys(CrmTaxonomy::stages()),
            self::NON_FUNNEL_STAGES,
        ));
    }

    private function funnel($user, Carbon $from, Carbon $to, array $cross = []): array
    {
        $events = $this->stageEvents($user, $from, $to, $cross);

        $stages = $this->funnelStages();
        $colors = CrmTaxonomy::stageColors();
        $labels = CrmTaxonomy::allStages();

        $values = array_map(fn ($key) => $events[$key] ?? 0, $stages);
        $widest = max($values ?: [0]);

        $bands = [];
        $above = null;

        foreach ($stages as $index => $key) {
            $value = $values[$index];

            $bands[] = [
                'key' => $key,
                'label' => $labels[$key] ?? $key,
                'value' => $value,
                // the same colour the stage has in a badge, a chip and both
                // stage charts — `lead_stages.color` is the only place a
                // stage's colour is written down
                'color' => $colors[$key] ?? null,
                /*
                 | How wide to draw the band, as a share of the widest one.
                 | Against the widest rather than against the top band, because
                 | the top band is `fresh` and `fresh` is always zero — every
                 | band would be a division by nothing.
                 */
                'width' => $widest > 0 ? round($value / $widest * 100, 2) : 0.0,
                /*
                 | The drop from the band above. Null at the top, where there
                 | is no band above, and null when the band above is empty —
                 | an em dash, never 0%. A negative value is a band that grew,
                 | which this shape allows and the front end says out loud.
                 */
                'drop' => ($above === null || $above === 0)
                    ? null
                    : round(($above - $value) / $above * 100, 1),
            ];

            $above = $value;
        }

        return ['bands' => $bands, 'total' => $widest];
    }

    private function bySource($user, Carbon $from, Carbon $to, array $cross = []): array
    {
        $counts = $this->crossFilterLeads(Lead::visibleTo($user), $cross)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('source, count(*) as total')
            ->groupBy('source')
            ->pluck('total', 'source');

        $sum = $counts->sum();

        // a pie drops empty slices, unlike a bar chart
        return collect(CrmTaxonomy::sourceUniverse($counts->keys()))
            ->map(fn ($label, $key) => [
                // the config key as well as its label, so a click on a slice
                // knows which source it is filtering by without the front end
                // having to look a label back up
                'key' => $key,
                'label' => $label,
                'value' => (int) ($counts[$key] ?? 0),
                'percent' => $sum > 0 ? round(($counts[$key] ?? 0) / $sum * 100, 1) : 0,
            ])
            ->filter(fn ($row) => $row['value'] > 0)
            ->values()->all();
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
    private function followUps($user, string $scope, array $cross = []): array
    {
        /*
         | hasLead() sits inside the closure, so the rows and the total it is
         | compared against are counted the same way — and so does the
         | cross-filter, for exactly the same reason.
         |
         | The cross-filter is the ONE thing from the rest of the page that
         | reaches these two panels. The date range still does not and must
         | not: "which of the leads I am looking at owe me a call" is a
         | sensible question, "which calls are due today, in August" is not.
         */
        $query = fn () => $this->crossFilterTodos(
            $scope === 'overdue'
                ? Todo::forUser($user)->hasLead()->overdue()
                : Todo::forUser($user)->hasLead()->dueToday(),
            $cross,
        );

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
                    /*
                     | assigned_to and assigned_role are not on the panel rows;
                     | CompleteTaskModal opens from them and needs both for its
                     | clash check — see TodoController::index().
                     */
                    'lead:id,first_name,middle_name,last_name,mobile_number,stage,stage_changed_at,created_at,not_connected_count,assigned_to,assigned_role',
                    // role too: the panel prints "name · role" under the lead
                    'owner:id,first_name,last_name,role',
                ])
                ->orderBy('scheduled_at')
                ->limit(self::PANEL_ROWS)
                ->get()
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
        $due = fn () => Todo::forUser($user)->hasLead()->pending()
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
            'total' => $total,
            'shown' => $rows->count(),
            // "and 4 more" — the difference between what is listed and what is
            // owed, computed here so the modal never has to subtract anything
            'more' => $total - $rows->count(),
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
     * @param  callable(): Builder  $due
     * @param  Collection<int, Todo>  $rows
     * @return list<array{name: ?string, count: int, rows: list<array<string, mixed>>}>
     */
    private function digestGroups(User $user, callable $due, $rows): array
    {
        if (! $user->isAdmin()) {
            return [[
                'name' => null,
                'count' => $rows->count(),
                'rows' => $rows->map(fn (Todo $todo) => $this->digestRow($todo))->all(),
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
            ->map(fn ($group, $id) => [
                'name' => $names[$id]?->display_name ?? 'Unassigned',
                'count' => (int) ($counts[$id] ?? $group->count()),
                'rows' => $group->map(fn (Todo $todo) => $this->digestRow($todo))->values()->all(),
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
            'id' => $todo->id,
            'name' => $todo->lead?->full_name,
            'mobile' => $todo->lead?->mobile_number,
            'stage' => $todo->lead?->stage,
            'at' => $todo->scheduled_at?->toIso8601String(),
            'earlier' => $todo->scheduled_at !== null
                && $todo->scheduled_at->lt(today()->startOfDay()),
        ];
    }
}
