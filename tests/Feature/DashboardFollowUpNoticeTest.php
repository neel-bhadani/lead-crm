<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The sign-in notice: what is owed today, said once, to the right person.
 *
 * Three things are being asserted here and they are separable. Who sees what
 * is scopeForUser() and nothing else — the front end is never sent a row it
 * should not have. What counts is the Pending follow-ups card's population,
 * everything still open that was due today or earlier. And "once per session"
 * is a server-side flag, so a dismissed notice is not hidden on the next visit,
 * it is not sent.
 *
 * @see \App\Http\Controllers\DashboardController::todayDigest()
 */
class DashboardFollowUpNoticeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $telecaller;
    private User $salesperson;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin       = $this->user('admin', 'Rajesh');
        $this->telecaller  = $this->user('telecaller', 'Priya');
        $this->salesperson = $this->user('salesperson', 'Amit');
        $this->project     = Project::create(['name' => 'Alpha']);

        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00'));
    }

    /* ---------------- who sees what ---------------- */

    /**
     * An admin gets the team's list and the breakdown that makes it readable:
     * seven follow-ups is not an instruction to anybody until it says whose.
     */
    public function test_an_admin_sees_the_whole_team_grouped_by_assignee(): void
    {
        $this->tasks($this->telecaller, 3);
        $this->tasks($this->salesperson, 4);

        $digest = $this->digest($this->admin);

        $this->assertSame(7, $digest['total']);
        $this->assertSame(
            [['name' => 'Amit', 'count' => 4], ['name' => 'Priya', 'count' => 3]],
            $digest['groups'],
            'the breakdown is ordered by workload and totals the heading'
        );
        $this->assertSame(7, array_sum(array_column($digest['groups'], 'count')));
    }

    /** A telecaller gets their own rows and no breakdown — it would be one line. */
    public function test_a_telecaller_sees_only_their_own(): void
    {
        $this->tasks($this->telecaller, 3);
        $this->tasks($this->salesperson, 4);

        $digest = $this->digest($this->telecaller);

        $this->assertSame(3, $digest['total']);
        $this->assertSame([], $digest['groups']);
        $this->assertSame(['Priya Shah'], array_unique(array_column($digest['rows'], 'owner')));
    }

    public function test_a_salesperson_sees_only_their_own(): void
    {
        $this->tasks($this->telecaller, 3);
        $this->tasks($this->salesperson, 4);

        $digest = $this->digest($this->salesperson);

        $this->assertSame(4, $digest['total']);
        $this->assertSame([], $digest['groups']);
        $this->assertSame(['Amit Patel'], array_unique(array_column($digest['rows'], 'owner')));
    }

    /* ---------------- what counts ---------------- */

    /** Nothing owed, nothing rendered. An empty notice is worse than none. */
    public function test_nothing_is_sent_when_nothing_is_due(): void
    {
        $this->assertNull($this->digest($this->admin));
    }

    /**
     * Yesterday's uncalled lead is the row this notice most needs to surface,
     * so the cut is "due today or earlier", not "due today".
     */
    public function test_tasks_from_earlier_days_are_included_and_come_first(): void
    {
        $lead = $this->lead($this->telecaller);

        $this->todo($lead, Carbon::parse('2026-09-02 16:00'));   // later today
        $this->todo($lead, Carbon::parse('2026-08-30 10:00'));   // three days late

        $digest = $this->digest($this->telecaller);

        $this->assertSame(2, $digest['total']);
        $this->assertTrue($digest['rows'][0]['overdue'], 'the oldest is listed first');
        $this->assertFalse($digest['rows'][1]['overdue']);
    }

    /** Tomorrow is not today's problem, and a closed task is nobody's. */
    public function test_future_completed_and_cancelled_tasks_are_left_out(): void
    {
        $lead = $this->lead($this->telecaller);

        $this->todo($lead, Carbon::parse('2026-09-03 10:00'));                          // tomorrow
        $this->todo($lead, now())->update(['status' => 'completed']);
        $this->todo($lead, now())->update(['status' => 'cancelled']);
        $this->todo($lead, now());                                                      // the only one

        $this->assertSame(1, $this->digest($this->telecaller)['total']);
    }

    /** A soft-deleted lead's task is not work anybody is going to do. */
    public function test_a_deleted_leads_task_is_not_counted(): void
    {
        $lead = $this->lead($this->telecaller);
        $this->todo($lead, now());

        $lead->delete();

        $this->assertNull($this->digest($this->telecaller));
    }

    /**
     * The list is capped; the count is not. The heading has to be the real
     * total or the button beside it is a lie about what is behind it.
     */
    public function test_a_long_list_is_capped_but_the_count_is_the_real_total(): void
    {
        $this->tasks($this->telecaller, 12);

        $digest = $this->digest($this->telecaller);

        $this->assertSame(12, $digest['total']);
        $this->assertCount(5, $digest['rows']);
    }

    /** Every row carries what the panel prints, and nothing it does not. */
    public function test_each_row_carries_the_name_mobile_time_and_stage(): void
    {
        $lead = $this->lead($this->telecaller);
        $this->todo($lead, Carbon::parse('2026-09-02 16:00'));

        $row = $this->digest($this->telecaller)['rows'][0];

        $this->assertSame('Meera Rani Sharma', $row['name']);
        $this->assertSame($lead->mobile_number, $row['mobile']);
        $this->assertSame('in_discussion', $row['stage']);
        $this->assertStringStartsWith('2026-09-02T16:00:00', $row['at']);
    }

    /* ---------------- once per session ---------------- */

    /** Shown on arrival, gone on every later visit in the same session. */
    public function test_the_notice_is_sent_once_per_session(): void
    {
        $this->tasks($this->telecaller, 2);

        $this->assertNotNull($this->digest($this->telecaller));
        $this->assertNull($this->digest($this->telecaller));
        $this->assertNull($this->digest($this->telecaller));
    }

    /**
     * Dismissing is a client-side hide over a server-side fact: by the time the
     * user closes the panel the session has already been marked, so the next
     * dashboard visit does not send it. Nothing has to be posted for that, and
     * this is the assertion that says so — no dismiss request is made here.
     */
    public function test_a_dismissed_notice_does_not_come_back_within_the_session(): void
    {
        $this->tasks($this->telecaller, 2);

        $this->digest($this->telecaller);            // shown, and dismissed on the page

        // more work arrives; it is still the same session, so it still waits
        $this->tasks($this->telecaller, 5);

        $this->assertNull($this->digest($this->telecaller));
    }

    /** A new session is a new arrival: signing in again is told again. */
    public function test_a_fresh_session_is_told_again(): void
    {
        $this->tasks($this->telecaller, 2);

        $this->assertNotNull($this->digest($this->telecaller));

        $this->flushSession();

        $this->assertNotNull($this->digest($this->telecaller));
    }

    /**
     * "Once per session" is about the notice, not about the check. A user who
     * signs in to an empty list at nine should still be told about the task
     * that lands at ten.
     */
    public function test_an_empty_check_does_not_use_up_the_notice(): void
    {
        $this->assertNull($this->digest($this->telecaller));

        $this->tasks($this->telecaller, 1);

        $this->assertNotNull($this->digest($this->telecaller));
    }

    /**
     * Logging a call reloads cards, charts and followUps. The notice is not in
     * that list, so its closure is never invoked and the one showing this
     * session was owed is not burned by a background refresh.
     */
    public function test_a_partial_reload_neither_sends_nor_spends_the_notice(): void
    {
        $this->tasks($this->telecaller, 2);

        $partial = $this->actingAs($this->telecaller)->get('/dashboard', $this->inertia() + [
            'X-Inertia-Partial-Data'      => 'cards',
            'X-Inertia-Partial-Component' => 'Dashboard',
        ]);

        $partial->assertJsonMissingPath('props.todayDigest');

        $this->assertNotNull($this->digest($this->telecaller), 'the notice was still owed');
    }

    /* ---------------- helpers ---------------- */

    /**
     * Passed to get() per request rather than through withHeaders(), which
     * merges into the client's defaults — the partial headers in one test
     * would then still be attached to every visit after it.
     */
    private function inertia(): array
    {
        return [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ];
    }

    private function digest(User $user): ?array
    {
        return $this->actingAs($user)
            ->get('/dashboard', $this->inertia())
            ->json('props.todayDigest');
    }

    /** $n tasks due today, on $n leads, all belonging to $owner. */
    private function tasks(User $owner, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->todo($this->lead($owner), now()->copy()->addMinutes($i));
        }
    }

    private function lead(User $owner): Lead
    {
        return Lead::create([
            'first_name' => 'Meera', 'middle_name' => 'Rani', 'last_name' => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $this->project->id, 'source' => 'walk_in',
            'stage' => 'in_discussion', 'assigned_to' => $owner->id,
            'created_by' => $this->admin->id,
        ]);
    }

    private function todo(Lead $lead, Carbon $at): Todo
    {
        return Todo::create([
            'lead_id'      => $lead->id,
            'assigned_to'  => $lead->assigned_to,
            'created_by'   => $this->admin->id,
            'scheduled_at' => $at,
            'type'         => 'call',
            'status'       => 'pending',
        ]);
    }

    private function user(string $role, string $first): User
    {
        return User::create([
            'first_name' => $first,
            'last_name'  => ['admin' => 'Mehta', 'telecaller' => 'Shah', 'salesperson' => 'Patel'][$role],
            'email' => "$role@example.test",
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
