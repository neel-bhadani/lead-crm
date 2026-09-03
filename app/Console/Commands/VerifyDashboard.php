<?php

namespace App\Console\Commands;

use App\Http\Controllers\DashboardController;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Proves every dashboard figure against a query written independently of the
 * controller.
 *
 * The point of the exercise is that the two sides share nothing. The dashboard
 * column comes from actually rendering the page — the real controller, the real
 * Eloquent scopes, the real GROUP BY. The independent column is hand-written
 * SQL, one COUNT per figure where the controller runs a single grouped query,
 * with the zero-filling done separately. A mistake would have to be made twice
 * to survive that.
 *
 * Reports only. It never writes, so it is safe against production.
 */
class VerifyDashboard extends Command
{
    protected $signature = 'crm:verify-dashboard
                            {--user= : audit as this user id (default: the first admin)}
                            {--from= : custom range start, Y-m-d}
                            {--to=   : custom range end, Y-m-d}';

    protected $description = 'Check every dashboard card and chart against an independent query, for every range';

    private int $failures = 0;

    /** @var list<string> */
    private array $detail = [];

    /** The snapshot as each range saw it — it must be the same every time. */
    private array $pipelineByRange = [];

    public function handle(): int
    {
        $user = $this->option('user')
            ? User::findOrFail($this->option('user'))
            : User::where('role', 'admin')->firstOrFail();

        $to   = $this->option('to')   ?: Carbon::today()->toDateString();
        $from = $this->option('from') ?: Carbon::today()->subDays(13)->toDateString();

        $this->newLine();
        $this->info("Verifying as {$user->display_name} ({$user->role})  —  "
            . now()->toDateTimeString() . ' ' . config('app.timezone'));
        $this->newLine();

        $ranges = [
            'Today'        => [['range' => 'today'], $this->range('today')],
            'Last 7 days'  => [['range' => '7'],     $this->range('7')],
            'Last 30 days' => [['range' => '30'],    $this->range('30')],
            "Custom $from..$to" => [compact('from', 'to'), $this->range('custom', $from, $to)],
        ];

        $rows = [];

        foreach ($ranges as $name => [$query, [$start, $end]]) {
            $rows = array_merge($rows, $this->compareRange($user, $name, $query, $start, $end));
            $rows[] = new \Symfony\Component\Console\Helper\TableSeparator();
        }

        $this->table(['Range', 'Metric', 'Dashboard', 'Independent query', ''], $rows);

        /*
         | The snapshot is the one chart on the page that must not move when the
         | range does. Checking it inside a single range cannot catch a filter
         | creeping back in — only comparing across all four can.
         */
        $this->newLine();
        $distinct = array_unique(array_map('json_encode', $this->pipelineByRange));

        if (count($distinct) === 1) {
            $this->info('Pipeline snapshot is identical in all ' . count($this->pipelineByRange)
                . ' ranges: ' . array_sum(reset($this->pipelineByRange)) . ' leads.');
        } else {
            $this->failures++;
            $this->error('Pipeline snapshot CHANGED with the range — a date filter has come back:');
            foreach ($this->pipelineByRange as $range => $bars) {
                $this->line("  $range: " . array_sum($bars) . ' — ' . json_encode($bars));
            }
        }

        if ($this->detail) {
            $this->newLine();
            $this->warn('Mismatch detail');
            foreach ($this->detail as $d) {
                $this->line($d);
            }
        }

        $this->newLine();

        if ($this->failures === 0) {
            $this->info('Every figure matches its independent query.');

            return self::SUCCESS;
        }

        $this->error("{$this->failures} figures disagree with their independent query.");

        return self::FAILURE;
    }

    /* ------------------------------------------------------------------ *
     |  The dashboard side: render the real page and read its props.
     * ------------------------------------------------------------------ */

    private function dashboard(User $user, array $query): array
    {
        $request = Request::create('/dashboard', 'GET', $query + ['reset' => 1]);
        $request->headers->set('X-Inertia', 'true');
        $request->setLaravelSession(new Store('verify', new ArraySessionHandler(120)));

        /*
         | Bind before setting the resolver, not after. The container's `request`
         | rebinding handler installs the auth guard's user resolver, and it
         | would quietly overwrite one set on the line above — leaving
         | $request->user() null and every visibleTo() call throwing.
         */
        $this->laravel->instance('request', $request);
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        $inertia  = $this->laravel->make(DashboardController::class)->index($request);
        $response = $inertia->toResponse($request);

        return json_decode($response->getContent(), true)['props'];
    }

    /* ------------------------------------------------------------------ *
     |  The independent side. Raw SQL, and PHP where the controller uses SQL.
     * ------------------------------------------------------------------ */

    /**
     * The four ranges, spelled out. startOfDay() to endOfDay() every time —
     * a bare subDays(7) keeps the current time of day and silently drops the
     * earliest morning.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(string $key, ?string $from = null, ?string $to = null): array
    {
        $today = Carbon::today();   // IST: the app timezone is Asia/Kolkata

        return match ($key) {
            'today' => [$today->copy()->startOfDay(), $today->copy()->endOfDay()],
            '7'     => [$today->copy()->subDays(6)->startOfDay(),  $today->copy()->endOfDay()],
            '30'    => [$today->copy()->subDays(29)->startOfDay(), $today->copy()->endOfDay()],
            default => [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()],
        };
    }

    /**
     * Visibility, as SQL. A lead metric is scoped by who owns the lead; the
     * work-list metric is scoped by who owns the to-do. That is the same split
     * the controller makes, and the reason the two cannot share one clause.
     */
    private function leadScope(User $u, string $alias = 'l'): string
    {
        return $u->role === 'admin' ? '' : " AND $alias.assigned_to = " . (int) $u->id;
    }

    private function todoScope(User $u, string $alias = 't'): string
    {
        return $u->role === 'admin' ? '' : " AND $alias.assigned_to = " . (int) $u->id;
    }

    private function count(string $sql): int
    {
        return (int) DB::selectOne($sql)->n;
    }

    /** All six cards plus the conversion percentage. */
    private function cards(User $u, Carbon $from, Carbon $to): array
    {
        $f  = $from->format('Y-m-d H:i:s');
        $t  = $to->format('Y-m-d H:i:s');
        $ls = $this->leadScope($u);
        $ts = $this->todoScope($u);
        $eod = Carbon::today()->endOfDay()->format('Y-m-d H:i:s');
        $todayStr = Carbon::today()->toDateString();

        // 1. Total leads — intake, so leads.created_at
        $total = $this->count("SELECT COUNT(*) n FROM leads l
                                WHERE l.deleted_at IS NULL
                                  AND l.created_at BETWEEN '$f' AND '$t' $ls");

        // 2. Today's leads — deliberately ignores the selected range
        $today = $this->count("SELECT COUNT(*) n FROM leads l
                                WHERE l.deleted_at IS NULL
                                  AND DATE(l.created_at) = '$todayStr' $ls");

        /*
         | 3, 4, 5. Site visits / Bookings / Lost.
         |
         | One query, one string swapped. Filtered on todos.completed_at, when
         | the event happened, never on the lead's creation date — a lead
         | created 40 days ago that booked today is a booking that happened
         | today. COUNT(DISTINCT lead_id), because one lead can reach the same
         | stage twice. Joined to leads so a soft-deleted lead takes its
         | history off the dashboard with it.
         */
        $happened = fn (string $stage) => $this->count(
            "SELECT COUNT(DISTINCT t.lead_id) n
               FROM todos t JOIN leads l ON l.id = t.lead_id
              WHERE l.deleted_at IS NULL
                AND t.outcome_stage = '$stage'
                AND t.completed_at BETWEEN '$f' AND '$t' $ls"
        );

        // 6. Pending follow-ups — stock, never date filtered. Everything still
        //    open that was due today or earlier: the two panels, added up.
        $pending = $this->count("SELECT COUNT(*) n
                                   FROM todos t JOIN leads l ON l.id = t.lead_id
                                  WHERE l.deleted_at IS NULL
                                    AND t.status = 'pending'
                                    AND t.scheduled_at <= '$eod' $ts");

        // Conversion — one cohort, one question: of the leads created in this
        // range, how many have booked since. Numerator inside the denominator.
        $cohort = $this->count("SELECT COUNT(DISTINCT l.id) n FROM leads l
                                 WHERE l.deleted_at IS NULL
                                   AND l.created_at BETWEEN '$f' AND '$t' $ls
                                   AND EXISTS (SELECT 1 FROM todos t2
                                                WHERE t2.lead_id = l.id
                                                  AND t2.outcome_stage = 'booking_done'
                                                  AND t2.completed_at IS NOT NULL)");

        return [
            'total'      => $total,
            'today'      => $today,
            'visits'     => $happened('site_visit_done'),
            'booked'     => $happened('booking_done'),
            'lost'       => $happened('lost'),
            'pending'    => $pending,
            // guarded: a dash when there is nothing to divide by, never 0%
            'conversion' => $total > 0 ? round($cohort / $total * 100, 1) : null,
        ];
    }

    /**
     * The stages reached inside the range, one COUNT(DISTINCT lead_id) per
     * stage — deliberately one query each, where the controller now runs a
     * single GROUP BY. A grouping mistake would show up here as a disagreement
     * rather than being shared by both sides.
     *
     * @return array<string, int>
     */
    private function stageChanges(User $u, Carbon $from, Carbon $to): array
    {
        $f  = $from->format('Y-m-d H:i:s');
        $t  = $to->format('Y-m-d H:i:s');
        $ls = $this->leadScope($u);

        $out = [];

        foreach (array_keys(config('crm.stages')) as $stage) {
            $out[$stage] = $this->count(
                "SELECT COUNT(DISTINCT t.lead_id) n
                   FROM todos t JOIN leads l ON l.id = t.lead_id
                  WHERE l.deleted_at IS NULL
                    AND t.outcome_stage = '$stage'
                    AND t.completed_at BETWEEN '$f' AND '$t' $ls"
            );
        }

        return $out;   // zero-filled: every configured stage has a key
    }

    /** Chart 1 — where the pipeline stands now. No date filter, by design. */
    private function byStage(User $u): array
    {
        $ls = $this->leadScope($u);

        $map = [];
        foreach (DB::select("SELECT l.stage s, COUNT(*) n FROM leads l
                              WHERE l.deleted_at IS NULL $ls GROUP BY l.stage") as $r) {
            $map[$r->s] = (int) $r->n;
        }

        $out = [];
        foreach (array_keys(config('crm.stages')) as $k) {
            $out[$k] = $map[$k] ?? 0;      // zero-filled: a missing bar reads as a bug
        }

        return $out;
    }

    /** Chart 4 — pending to-dos by type. Stock, so no date filter. */
    private function byTodoType(User $u): array
    {
        $ts = $this->todoScope($u);

        $map = [];
        foreach (DB::select("SELECT t.type ty, COUNT(*) n
                               FROM todos t JOIN leads l ON l.id = t.lead_id
                              WHERE l.deleted_at IS NULL
                                AND t.status = 'pending' $ts
                              GROUP BY t.type") as $r) {
            $map[$r->ty] = (int) $r->n;
        }

        $out = [];
        foreach (array_keys(config('crm.todo_types')) as $k) {
            $out[$k] = $map[$k] ?? 0;      // zero-filled: all four bars always render
        }

        return $out;
    }

    /** Chart 3 — leads by source, on leads.created_at. */
    private function bySource(User $u, Carbon $from, Carbon $to): array
    {
        $f = $from->format('Y-m-d H:i:s');
        $t = $to->format('Y-m-d H:i:s');
        $ls = $this->leadScope($u);

        $map = [];
        foreach (DB::select("SELECT l.source s, COUNT(*) n FROM leads l
                              WHERE l.deleted_at IS NULL
                                AND l.created_at BETWEEN '$f' AND '$t' $ls
                              GROUP BY l.source") as $r) {
            if ((int) $r->n > 0) {
                $map[$r->s] = (int) $r->n;     // a doughnut drops empty slices
            }
        }

        ksort($map);

        return $map;
    }

    /* ------------------------------------------------------------------ *
     |  Comparison
     * ------------------------------------------------------------------ */

    private function compareRange(User $user, string $name, array $query, Carbon $from, Carbon $to): array
    {
        $props  = $this->dashboard($user, $query);
        $cards  = $props['cards'];
        $charts = $props['charts'];

        $iCards = $this->cards($user, $from, $to);

        // the dashboard ships charts shaped for Chart.js; reshape to compare
        $dStage = [];
        foreach ($charts['byStage']['bars'] as $bar) {
            $dStage[$bar['key']] = $bar['value'];
        }

        $dChanges = [];
        foreach ($charts['stageChanges'] as $bar) {
            $dChanges[$bar['key']] = $bar['value'];
        }

        $sourceKey = array_flip(config('crm.sources'));
        $dSource   = [];
        foreach ($charts['bySource'] as $slice) {
            $dSource[$sourceKey[$slice['label']]] = $slice['value'];
        }
        ksort($dSource);

        /*
         | ksort, because the bars now arrive biggest-first and the independent
         | side builds its map in config order. `===` on arrays compares key
         | order too, so without this the check would fail on the ordering
         | rather than on any number being wrong. The order itself is asserted
         | where it belongs — see DashboardRangeTest.
         */
        $dTypes = [];
        foreach ($charts['byTodoType']['bars'] as $bar) {
            $dTypes[$bar['key']] = $bar['value'];
        }
        ksort($dTypes);

        $iSource = $this->bySource($user, $from, $to);

        $rows = [];

        $check = function (string $metric, $dash, $indep) use (&$rows, $name) {
            $ok = $dash === $indep
                || (is_numeric($dash) && is_numeric($indep) && abs($dash - $indep) < 0.05);

            if (! $ok) {
                $this->failures++;
                $this->detail[] = "  <fg=yellow>[$name] $metric</>\n"
                    . '      dashboard   : ' . json_encode($dash) . "\n"
                    . '      independent : ' . json_encode($indep);
            }

            $rows[] = [$name, $metric, $this->digest($dash), $this->digest($indep),
                       $ok ? '<fg=green>ok</>' : '<fg=red>MISMATCH</>'];
        };

        $check('Card 1  Total leads',        $cards['total'],   $iCards['total']);
        $check("Card 2  Today's leads",      $cards['today'],   $iCards['today']);
        $check('Card 3  Site visits done',   $cards['visits'],  $iCards['visits']);
        $check('Card 4  Bookings',           $cards['booked'],  $iCards['booked']);
        $check('Card 5  Lost',               $cards['lost'],    $iCards['lost']);
        $check('Card 6  Pending follow-ups', $cards['pending'], $iCards['pending']);
        $check('        Conversion %',       $cards['conversion'], $iCards['conversion']);

        $check('Chart 1 Pipeline right now',     $dStage,   $this->byStage($user));
        $check('Chart 1 Pipeline header total',  $charts['byStage']['total'], array_sum($dStage));
        $check('Chart 2 Stage changes in range', $dChanges, $this->stageChanges($user, $from, $to));
        $check('Chart 3 Leads by source',        $dSource,  $iSource);
        $iTypes = $this->byTodoType($user);
        ksort($iTypes);

        $check('Chart 4 To-dos by type',         $dTypes,   $iTypes);
        $check('Chart 4 To-do header total',     $charts['byTodoType']['total'], array_sum($dTypes));

        /*
         | Cross-checks. The two columns above can agree and the dashboard still
         | be incoherent with itself, so these ask the questions a reader of the
         | page would: does the stage chart account for every visible lead, and
         | does the Pending card equal the two panels it sits above?
         */
        $visibleLeads = $this->count('SELECT COUNT(*) n FROM leads l WHERE l.deleted_at IS NULL'
            . $this->leadScope($user));

        /*
         | The three ties the redesign turns on. These are the pairs a reader
         | is entitled to line up: the card and the bar are the same event,
         | counted the same way, so anything but equality is a bug.
         */
        $check('Tie     Bookings card = booking_done bar',
            $cards['booked'], $dChanges['booking_done']);
        $check('Tie     Site visits card = site_visit_done bar',
            $cards['visits'], $dChanges['site_visit_done']);
        $check('Tie     Lost card = lost bar',
            $cards['lost'], $dChanges['lost']);

        $check('Cross   pipeline totals all visible leads', array_sum($dStage), $visibleLeads);

        /*
         | The same question of chart 4: zero-filling across config('crm.todo_types')
         | silently drops a row whose type is not one of them, and the only way
         | to see that is to compare the bars against a count that does not
         | group by type at all.
         */
        $allPending = $this->count("SELECT COUNT(*) n
                                      FROM todos t JOIN leads l ON l.id = t.lead_id
                                     WHERE l.deleted_at IS NULL
                                       AND t.status = 'pending'" . $this->todoScope($user));

        $check('Cross   to-do bars total all pending to-dos', array_sum($dTypes), $allPending);

        $this->pipelineByRange[$name] = $dStage;
        $check('Cross   Pending card = both panels',
            $cards['pending'],
            $props['followUps']['today']['total'] + $props['followUps']['overdue']['total']);
        $check('Cross   conversion is within 0–100',
            $cards['conversion'] === null || ($cards['conversion'] >= 0 && $cards['conversion'] <= 100),
            true);

        return $rows;
    }

    /** A 30-bucket series has to fit in a table cell. */
    private function digest($value): string
    {
        if ($value === null)  return '—';
        if (is_bool($value))  return $value ? 'true' : 'false';
        if (! is_array($value)) return (string) $value;
        if ($value === [])    return 'n=0';

        $first = reset($value);

        if (is_string($first)) {
            return 'n=' . count($value) . ' [' . $first . ' … ' . end($value) . ']';
        }

        return 'n=' . count($value) . ' sum=' . array_sum(array_filter($value, 'is_numeric'));
    }
}
