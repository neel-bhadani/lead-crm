<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\FollowUpScheduler;
use App\Services\LeadFollowUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The first follow-up a lead gets, and the interval that used to be thrown away.
 *
 * onLeadCreated() scheduled at withinWorkingHours(now()) whatever stage the lead
 * arrived at. That was invisible while every lead started at `fresh`, whose
 * configured interval is 0 — now() and now()+0h are the same moment. Once leads
 * started being typed in as already visited, the 24 and 48 hours in
 * config('crm.followup_hours') were being ignored: a lead added at 2:03 PM as
 * `site_visit_done` asked for a call back at 2:03 PM.
 *
 * Nothing here hardcodes an interval. Every expectation is built from the config
 * and from FollowUpScheduler, so changing an hour in config/crm.php moves the
 * assertions with it and a second copy of the rule cannot drift in.
 *
 * @see \App\Services\LeadFollowUpService::onLeadCreated()
 */
class LeadCreationSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'first_name' => 'Ann', 'last_name' => 'User',
            'email' => 'admin@example.test', 'mobile_number' => '9000000001',
            'role' => 'admin', 'is_active' => true, 'password' => 'password',
        ]);

        $this->project = Project::create(['name' => 'Alpha']);

        config([
            'crm.working_hours' => ['start' => 9, 'end' => 18],
            'crm.working_days'  => [1, 2, 3, 4, 5, 6, 7],
            'crm.holidays'      => [],
        ]);

        // a Wednesday, mid-afternoon: inside working hours, so the clamp is not
        // doing any of the work in the interval assertions below
        Carbon::setTestNow(Carbon::parse('2026-09-02 14:03'));
    }

    /* ---------------- the interval ---------------- */

    /** The reported bug, in one assertion. */
    public function test_a_lead_created_at_site_visit_done_waits_twenty_four_hours(): void
    {
        $todo = $this->createAt('site_visit_done');

        $this->assertNotNull($todo, 'an open lead must have a pending task');
        $this->assertSame(
            now()->copy()->addHours(config('crm.followup_hours.site_visit_done'))->format('Y-m-d H:i'),
            $todo->scheduled_at->format('Y-m-d H:i'),
            'the configured interval was ignored'
        );
        // and emphatically not the moment it was created
        $this->assertNotSame(now()->format('Y-m-d H:i'), $todo->scheduled_at->format('Y-m-d H:i'));
    }

    /**
     * Every non-terminal stage, against the scheduler rather than against a
     * table of hours typed out a second time.
     */
    public function test_every_creation_stage_gets_the_interval_its_stage_configures(): void
    {
        foreach (array_keys(config('crm.stages')) as $stage) {
            if (in_array($stage, config('crm.terminal_stages'))) {
                continue;
            }

            $todo = $this->createAt($stage);
            $want = app(FollowUpScheduler::class)->next(new Lead(['not_connected_count' => 0]), $stage);

            $this->assertNotNull($todo, "$stage: an open lead must have a pending task");
            $this->assertSame($want->format('Y-m-d H:i'), $todo->scheduled_at->format('Y-m-d H:i'), $stage);
        }
    }

    /** `fresh` is the interval-0 case, and it must not have moved. */
    public function test_a_fresh_lead_is_still_called_straight_away(): void
    {
        $this->assertSame(0, config('crm.followup_hours.fresh'), 'the premise of this test');

        $this->assertSame(
            now()->format('Y-m-d H:i'),
            $this->createAt('fresh')->scheduled_at->format('Y-m-d H:i')
        );
    }

    /**
     * A lead typed in as not connected starts at the first rung of the ladder,
     * not at the ordinary interval.
     *
     * Clamped, like everything else: 2:03 PM plus four hours is 6:03 PM, which
     * is past closing, so it lands at opening the next morning.
     */
    public function test_a_lead_created_at_not_connected_starts_the_retry_ladder(): void
    {
        $todo = $this->createAt('not_connected');

        $ladder = app(FollowUpScheduler::class)
            ->withinWorkingHours(now()->copy()->addHours(config('crm.retry_hours.1')));

        $this->assertSame($ladder->format('Y-m-d H:i'), $todo->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('2026-09-03 09:00', $todo->scheduled_at->format('Y-m-d H:i'));
    }

    /* ---------------- the clamp still applies ---------------- */

    /**
     * The interval and the clamp compose. 6 PM is closing time, so a lead added
     * then at `site_visit_done` is 24 hours out — 6 PM tomorrow, still shut —
     * and lands at opening the morning after that.
     */
    public function test_a_lead_created_at_six_pm_respects_working_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 18:00'));

        $this->assertSame('2026-09-04 09:00',
            $this->createAt('site_visit_done')->scheduled_at->format('Y-m-d H:i'));

        // and the interval-0 case is the next morning, not the one after
        $this->assertSame('2026-09-03 09:00',
            $this->createAt('fresh')->scheduled_at->format('Y-m-d H:i'));
    }

    /** A closed day is stepped over on creation exactly as it is on a call. */
    public function test_creation_steps_over_a_holiday(): void
    {
        config(['crm.holidays' => ['2026-09-03']]);

        // 24 hours from Wednesday afternoon is Thursday, which is shut
        $this->assertSame('2026-09-04 09:00',
            $this->createAt('site_visit_done')->scheduled_at->format('Y-m-d H:i'));
    }

    /* ---------------- no task, and the invariant ---------------- */

    /** Booked or lost on arrival: nothing to schedule. */
    public function test_a_lead_created_at_a_terminal_stage_gets_no_pending_task(): void
    {
        foreach (config('crm.terminal_stages') as $stage) {
            $this->assertNull($this->createAt($stage), "$stage must not get a task");
        }

        $this->assertSame(0, Todo::where('status', 'pending')->count());
    }

    /** The rule the whole To-do page rests on, across every creation stage. */
    public function test_every_creation_stage_leaves_the_pending_todo_invariant_intact(): void
    {
        foreach (array_keys(config('crm.stages')) as $stage) {
            $this->createAt($stage);
        }

        $this->assertSame(9, Lead::count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
        // exactly one pending task per open lead, never two
        $this->assertSame(Lead::open()->count(), Todo::where('status', 'pending')->count());
    }

    /* ---------------- the backfill ---------------- */

    /**
     * The backfill writes one history row, for the stage the lead was created
     * at, and it is `completed` — so it can never become somebody's task and
     * cannot affect the invariant above. No row is written for the stages
     * below it, so there is nothing there to schedule from either.
     */
    public function test_the_creation_backfill_writes_one_completed_row_and_no_task(): void
    {
        $this->createAt('site_visit_done');

        $history = Todo::whereNotNull('outcome_stage')->get();

        $this->assertCount(1, $history, 'one row, for the stage it was created at');
        $this->assertSame('site_visit_done', $history->first()->outcome_stage);
        $this->assertSame('completed', $history->first()->status);

        // and the only pending row is the scheduled follow-up, not a backfill
        $pending = Todo::where('status', 'pending')->get();

        $this->assertCount(1, $pending);
        $this->assertNull($pending->first()->outcome_stage);
    }

    /** `fresh` is arrival, not a transition, so it writes no history at all. */
    public function test_a_fresh_lead_writes_no_history_row(): void
    {
        $this->createAt('fresh');

        $this->assertSame(0, Todo::whereNotNull('outcome_stage')->count());
        $this->assertSame(1, Todo::where('status', 'pending')->count());
    }

    /* ---------------- through the real controller ---------------- */

    /** The same answer through the HTTP path the form actually uses. */
    public function test_the_lead_form_schedules_the_same_task(): void
    {
        $this->actingAs($this->admin)->post('/leads', [
            'first_name'    => 'Meera',
            'last_name'     => 'Sharma',
            'mobile_number' => '9000000123',
            'project_id'    => $this->project->id,
            'source'        => 'walk_in',
            'stage'         => 'site_visit_done',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $todo = Todo::where('status', 'pending')->firstOrFail();

        $this->assertSame(
            now()->copy()->addHours(config('crm.followup_hours.site_visit_done'))->format('Y-m-d H:i'),
            $todo->scheduled_at->format('Y-m-d H:i')
        );
    }

    /* ---------------- fixtures ---------------- */

    /** Create a lead at $stage and hand back its pending task, if it got one. */
    private function createAt(string $stage): ?Todo
    {
        $this->actingAs($this->admin);

        $lead = Lead::create([
            'first_name'    => 'Meera',
            'last_name'     => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id'    => $this->project->id,
            'source'        => 'walk_in',
            'stage'         => $stage,
            'assigned_to'   => $this->admin->id,
            'created_by'    => $this->admin->id,
        ]);

        app(LeadFollowUpService::class)->onLeadCreated($lead);

        return Todo::where('lead_id', $lead->id)->where('status', 'pending')->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
