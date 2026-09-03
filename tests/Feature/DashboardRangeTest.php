<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\LeadFollowUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The range boundaries, and the two things that used to fall through them.
 *
 * Every range runs startOfDay() to endOfDay(). Today used to stop at now(),
 * which is a different boundary from the one Last 7 days and Last 30 days use,
 * and that difference is visible on the page: an event stamped later today
 * dropped out of Today while still counting in the longer ranges.
 *
 * @see \App\Http\Controllers\DashboardController::preset()
 */
class DashboardRangeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin   = $this->user('admin');
        $this->project = Project::create(['name' => 'Alpha']);

        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00'));
    }

    /* ---------------- the boundary ---------------- */

    /**
     * The shape of the reported bug, in miniature: one booking, two ranges,
     * two answers. With Today ending at now() the 20:00 booking was outside
     * Today and inside Last 7 days — the same event counted by one range and
     * not the other.
     */
    public function test_an_event_stamped_later_today_counts_in_today_not_only_in_the_longer_ranges(): void
    {
        $lead = $this->lead(now()->subDays(40));

        Carbon::setTestNow(Carbon::parse('2026-09-02 20:00'));
        $this->service()->changeStage($lead, 'booking_done', ['booked_unit' => 'A-1']);

        // ...and the page is looked at earlier in the day
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00'));

        $this->assertSame(1, $this->card('today', 'booked'), 'Today must reach 23:59:59');
        $this->assertSame(1, $this->card('7', 'booked'));
        $this->assertSame(1, $this->card('30', 'booked'));
    }

    /** 00:00 is inside Today. A range starting at now() would lose the morning. */
    public function test_a_lead_created_at_seven_in_the_morning_counts_in_todays_leads(): void
    {
        $this->lead(Carbon::parse('2026-09-02 07:00'));

        $this->assertSame(1, $this->card('today', 'today'));
        $this->assertSame(1, $this->card('today', 'total'));
        $this->assertSame(1, $this->card('7', 'total'));
    }

    /**
     * Last 7 days counts today as one of the seven and still reaches the
     * midnight at the far end. A raw subDays(7) would keep the current time of
     * day and silently drop that earliest morning.
     */
    public function test_the_seven_day_range_covers_seven_whole_days_ending_today(): void
    {
        $this->lead(Carbon::parse('2026-08-27 00:30'));   // first minute of day one
        $this->lead(Carbon::parse('2026-09-02 23:30'));   // last minute of today
        $this->lead(Carbon::parse('2026-08-26 23:30'));   // one minute too early

        $this->assertSame(2, $this->card('7', 'total'));
        $this->assertSame(3, $this->card('30', 'total'));
    }

    public function test_the_thirty_day_range_covers_thirty_whole_days_ending_today(): void
    {
        $this->lead(Carbon::parse('2026-08-04 00:30'));   // day one of thirty
        $this->lead(Carbon::parse('2026-08-03 23:30'));   // one minute too early

        $this->assertSame(1, $this->card('30', 'total'));
    }

    /** A custom range runs 00:00 on the From date to 23:59:59 on the To date. */
    public function test_a_custom_range_includes_both_end_days_in_full(): void
    {
        $this->lead(Carbon::parse('2026-08-10 00:10'));
        $this->lead(Carbon::parse('2026-08-12 23:50'));
        $this->lead(Carbon::parse('2026-08-09 23:50'));
        $this->lead(Carbon::parse('2026-08-13 00:10'));

        $this->assertSame(2, $this->cards('from=2026-08-10&to=2026-08-12')['total']);
    }

    /* ---------------- the history gap on creation ---------------- */

    /**
     * A lead typed in at "Site visit done" has had a site visit. It used to
     * write no history row — only the terminal stages did — so it appeared in
     * the Leads-by-stage chart under Site visit done and was never counted by
     * the Site visits done card, in any range. A card and a chart disagreeing
     * about the same lead.
     */
    public function test_a_lead_created_at_site_visit_done_counts_in_the_site_visits_card(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload(['stage' => 'site_visit_done']));

        $this->assertSame(1, Todo::where('outcome_stage', 'site_visit_done')
            ->where('status', 'completed')->count());
        $this->assertSame(1, $this->card('today', 'visits'));
        $this->assertSame(1, $this->card('30', 'visits'));
    }

    /** Still exactly one row, and still exactly one pending to-do behind it. */
    public function test_a_backfilled_creation_writes_one_history_row_and_one_pending_todo(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload(['stage' => 'connected']));

        $this->assertSame(1, Todo::whereNotNull('outcome_stage')->count());
        $this->assertSame(1, Todo::where('status', 'pending')->count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /** `fresh` is arrival, not a transition. It must still write nothing. */
    public function test_a_fresh_lead_still_writes_no_history(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload(['stage' => 'fresh']));

        $this->assertSame(0, Todo::whereNotNull('outcome_stage')->count());
        $this->assertSame(1, Todo::where('status', 'pending')->count());
    }

    /* ---------------- one action, one history row ---------------- */

    /**
     * Scheduling a site visit hands a telecaller's lead to a salesperson, and
     * handover() saves the lead a second time after applyStage() already has.
     * Both have to be holding the same instance: a second, staler copy would
     * write the old stage back over the new one and the lead would silently
     * revert while its history said otherwise.
     */
    public function test_a_handover_does_not_revert_the_stage_it_was_triggered_by(): void
    {
        $telecaller  = $this->user('telecaller');
        $salesperson = $this->user('salesperson');

        $lead = $this->lead(now()->subDays(2));
        $lead->update([
            'stage'         => 'details_shared',
            'assigned_to'   => $telecaller->id,
            'assigned_role' => 'telecaller',
        ]);

        $outcome = $this->service()->changeStage($lead->fresh(), 'site_visit_scheduled');

        $lead->refresh();

        $this->assertNotNull($outcome['handed_over_to'], 'the handover should have fired');
        $this->assertSame($salesperson->id, $lead->assigned_to);
        $this->assertSame('site_visit_scheduled', $lead->stage, 'the stage must survive the handover save');

        // and the history agrees with it, exactly once
        $this->assertSame(1, Todo::where('lead_id', $lead->id)
            ->where('outcome_stage', 'site_visit_scheduled')->count());
    }

    /** Logging a call writes the outcome onto the to-do it closed. One row. */
    public function test_logging_a_call_writes_exactly_one_history_row(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload(['stage' => 'fresh']));

        $lead = Lead::firstOrFail();
        $todo = $lead->pendingTodo;

        $this->actingAs($this->admin)
            ->post("/todos/{$todo->id}/complete", ['stage' => 'connected', 'remarks' => 'Spoke.']);

        $this->assertSame(1, Todo::whereNotNull('outcome_stage')->count());
        $this->assertSame('connected', Todo::whereNotNull('outcome_stage')->value('outcome_stage'));
        $this->assertSame('connected', $lead->fresh()->stage);
        // and the closed to-do is the history row, not a second one beside it
        $this->assertSame($todo->id, Todo::whereNotNull('outcome_stage')->value('id'));
    }

    /** A stage change from the lead form has no to-do to close, so it writes one. */
    public function test_a_lead_form_stage_change_writes_exactly_one_history_row(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload(['stage' => 'fresh']));

        $lead = Lead::firstOrFail();

        $this->actingAs($this->admin)->put("/leads/{$lead->id}", $this->payload([
            'mobile_number' => $lead->mobile_number,
            'stage'         => 'details_shared',
        ]));

        $this->assertSame('details_shared', $lead->fresh()->stage);
        $this->assertSame(1, Todo::whereNotNull('outcome_stage')->count());
        $this->assertSame(1, Todo::where('status', 'pending')->count());
    }

    /* ---------------- the charts ---------------- */

    /** An empty range still draws a full set of bars and a dash, not a gap. */
    public function test_an_empty_range_is_zero_filled_and_shows_a_dash(): void
    {
        $charts = $this->charts('from=2026-07-01&to=2026-07-05');
        $cards  = $this->cards('from=2026-07-01&to=2026-07-05');

        // the range-filtered stage chart keeps every bar, all of them zero
        $this->assertCount(9, $charts['stageChanges']);
        $this->assertSame(0, array_sum(array_column($charts['stageChanges'], 'value')));
        // the stage chart is a snapshot, so it keeps every bar whatever the range
        $this->assertCount(9, $charts['byStage']['bars']);

        $this->assertSame(0, $cards['total']);
        $this->assertNull($cards['conversion']);
    }

    /**
     * The stage chart is not date filtered, so a lead booked today is in it
     * whenever it was created — which is what stopped it contradicting the
     * Bookings card.
     */
    public function test_the_stage_chart_ignores_the_range_entirely(): void
    {
        $lead = $this->lead(now()->subDays(40));
        $this->service()->changeStage($lead, 'booking_done', ['booked_unit' => 'A-1']);

        foreach (['today', '7', '30'] as $range) {
            $bars = collect($this->charts("range=$range")['byStage']['bars'])->keyBy('key');

            $this->assertSame(1, $bars['booking_done']['value'], "range=$range");
            $this->assertSame(1, $this->card($range, 'booked'), "range=$range");
        }
    }

    /* ---------------- the two stage charts ---------------- */

    /**
     * The three ties the page is arranged around.
     *
     * "Stage changes in this range" and the three event cards come out of one
     * query — DashboardController::stageEvents() — so these cannot drift by
     * construction. They are asserted anyway, in every range, because "they
     * share a query" is a fact about today's code and this is a fact about the
     * output.
     */
    public function test_the_three_cards_equal_their_bars_in_every_range(): void
    {
        // one lead visits and books today; another was lost today; a third
        // booked long ago, so the ranges genuinely disagree with each other
        $visited = $this->lead(now()->subDays(40));
        $this->service()->changeStage($visited, 'site_visit_done');
        $this->service()->changeStage($visited->fresh(), 'booking_done', ['booked_unit' => 'A-1']);

        $this->service()->changeStage($this->lead(now()->subDays(3)), 'lost', ['reason' => 'budget']);

        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00'));
        $this->service()->changeStage($this->lead(now()->subDays(20)), 'booking_done', ['booked_unit' => 'A-2']);
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00'));

        $ranges = ['range=today', 'range=7', 'range=30', 'from=2026-08-01&to=2026-08-15'];

        foreach ($ranges as $query) {
            $cards = $this->cards($query);
            $bars  = collect($this->charts($query)['stageChanges'])->keyBy('key');

            $this->assertSame($cards['booked'], $bars['booking_done']['value'], $query);
            $this->assertSame($cards['visits'], $bars['site_visit_done']['value'], $query);
            $this->assertSame($cards['lost'],   $bars['lost']['value'], $query);
        }
    }

    /** One lead reaching a stage twice in a range is still one lead on the bar. */
    public function test_the_range_chart_counts_a_lead_once_per_stage(): void
    {
        $lead = $this->lead(now()->subDays(40));

        $this->service()->changeStage($lead, 'booking_done', ['booked_unit' => 'A-1']);
        $this->service()->changeStage($lead->fresh(), 'in_discussion');
        $this->service()->changeStage($lead->fresh(), 'booking_done', ['booked_unit' => 'A-1']);

        $this->assertSame(2, Todo::where('outcome_stage', 'booking_done')->count(), 'two events');

        $bars = collect($this->charts('range=today')['stageChanges'])->keyBy('key');

        $this->assertSame(1, $bars['booking_done']['value'], 'but one lead');
        $this->assertSame(1, $this->card('today', 'booked'));
    }

    /**
     * The snapshot is the one chart that must not move with the range. A filter
     * creeping back onto it is invisible inside any single range.
     */
    public function test_the_pipeline_snapshot_is_the_same_in_every_range(): void
    {
        $this->lead(now()->subDays(200));
        $this->lead(now()->subDays(40));
        $this->lead(now());

        $seen = [];

        foreach (['range=today', 'range=7', 'range=30', 'from=2026-08-01&to=2026-08-15'] as $query) {
            $chart  = $this->charts($query)['byStage'];
            $seen[] = $chart;

            // the header total is the bars, so the two can never disagree
            $this->assertSame(3, $chart['total'], $query);
            $this->assertSame(3, array_sum(array_column($chart['bars'], 'value')), $query);
            $this->assertSame(Lead::count(), $chart['total'], $query);
        }

        $this->assertCount(1, collect($seen)->unique(fn ($c) => json_encode($c)),
            'the pipeline snapshot changed with the range');
    }

    /** A soft-deleted lead leaves the snapshot, and its history leaves the chart. */
    public function test_a_deleted_lead_leaves_both_stage_charts(): void
    {
        $lead = $this->lead(now()->subDays(10));
        $this->service()->changeStage($lead, 'booking_done', ['booked_unit' => 'A-1']);

        $this->assertSame(1, $this->charts('range=today')['byStage']['total']);
        $this->assertSame(1, $this->card('today', 'booked'));

        $this->actingAs($this->admin)->delete("/leads/{$lead->id}");

        $charts = $this->charts('range=today');
        $bars   = collect($charts['stageChanges'])->keyBy('key');

        $this->assertSame(0, $charts['byStage']['total']);
        $this->assertSame(0, $bars['booking_done']['value']);
        $this->assertSame(0, $this->card('today', 'booked'));
    }

    /** Both stage charts render all nine stages, zeros included. */
    public function test_both_stage_charts_are_zero_filled_across_every_stage(): void
    {
        $charts = $this->charts('from=2026-07-01&to=2026-07-05');

        $this->assertCount(9, $charts['stageChanges']);
        $this->assertCount(9, $charts['byStage']['bars']);
        $this->assertSame(array_keys(config('crm.stages')), array_column($charts['stageChanges'], 'key'));
        $this->assertSame(array_keys(config('crm.stages')), array_column($charts['byStage']['bars'], 'key'));
        $this->assertSame(0, array_sum(array_column($charts['stageChanges'], 'value')));
    }

    /* ---------------- to-dos by type ---------------- */

    /**
     * The fourth chart is a backlog, so every configured type has a bar even
     * when nothing of that kind is outstanding. A missing category reads as a
     * bug; a zero bar reads as information.
     *
     * The bars are ordered by value, so this compares them as a set. With
     * nothing outstanding they are all zero and the stable sort leaves them in
     * config order, which is asserted too — a sort that shuffled equal values
     * would move the empty chart's bars around between page loads.
     */
    public function test_the_todo_type_chart_renders_every_configured_type(): void
    {
        $chart = $this->charts('range=30')['byTodoType'];

        $keys = array_column($chart['bars'], 'key');
        sort($keys);

        $expected = array_keys(config('crm.todo_types'));
        sort($expected);

        $this->assertSame($expected, $keys);
        $this->assertSame(array_keys(config('crm.todo_types')), array_column($chart['bars'], 'key'),
            'all-zero bars must keep config order, not shuffle');
        $this->assertSame(0, $chart['total']);
    }

    /**
     * Biggest first, and the zeros still there. Call is nearly the whole
     * backlog and used to be drawn wherever config happened to list it, beside
     * three near-empty bars.
     */
    public function test_the_todo_type_bars_are_ordered_biggest_first(): void
    {
        $lead = $this->lead(now()->subDays(3));

        // deliberately against config order: site_visit is listed last
        foreach (['site_visit', 'site_visit', 'site_visit', 'whatsapp'] as $type) {
            $this->todo($lead, now()->addDay(), $type);
        }

        $bars = $this->charts('range=30')['byTodoType']['bars'];

        $this->assertSame(['site_visit', 'whatsapp', 'call', 'meeting'], array_column($bars, 'key'));
        $this->assertSame([3, 1, 0, 0], array_column($bars, 'value'));

        // sorting is not filtering: every configured type still has a bar
        $this->assertCount(count(config('crm.todo_types')), $bars);
    }

    /**
     * Its bars account for every pending to-do, and its header total is the
     * bars — the same guarantee the pipeline snapshot's header carries.
     */
    public function test_the_todo_type_bars_total_every_pending_todo(): void
    {
        $lead = $this->lead(now()->subDays(3));

        foreach (['call', 'call', 'whatsapp', 'site_visit'] as $type) {
            $this->todo($lead, now()->addDay(), $type);
        }

        // a completed one is not outstanding work and must not be counted
        $this->todo($lead, now()->subDay(), 'meeting')->update(['status' => 'completed']);

        $chart = $this->charts('range=30')['byTodoType'];
        $bars  = collect($chart['bars'])->pluck('value', 'key');

        $this->assertSame(2, $bars['call']);
        $this->assertSame(1, $bars['whatsapp']);
        $this->assertSame(0, $bars['meeting']);
        $this->assertSame(1, $bars['site_visit']);

        $this->assertSame(4, $chart['total']);
        $this->assertSame(4, array_sum(array_column($chart['bars'], 'value')));
        $this->assertSame(Todo::where('status', 'pending')->count(), $chart['total']);
    }

    /** Stock, like the pipeline: no range moves it. */
    public function test_the_todo_type_chart_ignores_the_range_entirely(): void
    {
        $this->todo($this->lead(now()->subDays(200)), now()->addDays(3), 'call');

        $seen = [];

        foreach (['range=today', 'range=7', 'range=30', 'from=2026-08-01&to=2026-08-15'] as $query) {
            $seen[] = $this->charts($query)['byTodoType'];
        }

        $this->assertCount(1, collect($seen)->unique(fn ($c) => json_encode($c)),
            'the to-do backlog changed with the range');
        $this->assertSame(1, $seen[0]['total']);
    }

    /** A soft-deleted lead takes its outstanding work off the chart with it. */
    public function test_a_deleted_leads_todos_leave_the_type_chart(): void
    {
        $lead = $this->lead(now()->subDays(3));
        $this->todo($lead, now()->addDay(), 'call');

        $this->assertSame(1, $this->charts('range=30')['byTodoType']['total']);

        $this->actingAs($this->admin)->delete("/leads/{$lead->id}");

        $this->assertSame(0, $this->charts('range=30')['byTodoType']['total']);
    }

    /* ---------------- helpers ---------------- */

    private function service(): LeadFollowUpService
    {
        $this->actingAs($this->admin);

        return app(LeadFollowUpService::class);
    }

    private function props(string $only, string $query): array
    {
        return $this->actingAs($this->admin)->withHeaders([
            'X-Inertia'                   => 'true',
            'X-Inertia-Version'           => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Data'      => $only,
            'X-Inertia-Partial-Component' => 'Dashboard',
        ])->get("/dashboard?reset=1&$query")->json("props.$only");
    }

    private function cards(string $query): array
    {
        return $this->props('cards', $query);
    }

    private function charts(string $query): array
    {
        return $this->props('charts', $query);
    }

    private function card(string $range, string $key)
    {
        return $this->cards("range=$range")[$key];
    }

    private function user(string $role): User
    {
        return User::create([
            'first_name' => ucfirst($role), 'last_name' => 'User',
            'email' => "$role@example.test",
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'first_name'    => 'Meera',
            'last_name'     => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id'    => $this->project->id,
            'source'        => 'walk_in',
            'stage'         => 'fresh',
        ];
    }

    private function todo(Lead $lead, Carbon $at, string $type): Todo
    {
        return Todo::create([
            'lead_id'      => $lead->id,
            'assigned_to'  => $lead->assigned_to,
            'created_by'   => $this->admin->id,
            'scheduled_at' => $at,
            'type'         => $type,
            'status'       => 'pending',
        ]);
    }

    private function lead(Carbon $createdAt): Lead
    {
        $lead = Lead::create([
            'first_name' => 'Meera', 'last_name' => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $this->project->id, 'source' => 'walk_in',
            'stage' => 'in_discussion', 'assigned_to' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);

        $lead->forceFill(['created_at' => $createdAt])->save();

        return $lead;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
