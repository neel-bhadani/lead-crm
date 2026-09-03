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
 * The sign-in modal: what is owed today, said once, to the right person.
 *
 * Three things are being asserted here and they are separable. Who sees what
 * is scopeForUser() and nothing else — the front end is never sent a row it
 * should not have. What counts is the Calls pending card's population,
 * everything still open that was due today or earlier, in one list. And "once
 * per session" is a server-side flag, so a dismissed modal is not hidden on the
 * next visit, it is not sent.
 *
 * @see \App\Http\Controllers\DashboardController::todayDigest()
 */
class DashboardFollowUpModalTest extends TestCase
{
    use RefreshDatabase;

    /** Minutes past the fixed test now, so no two fixtures share a timestamp. */
    private int $minute = 0;

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
        $this->assertSame(['Priya Shah', 'Amit Patel'], array_column($digest['groups'], 'name'),
            'the oldest outstanding call heads the list, not the biggest pile');
        $this->assertSame([3, 4], array_column($digest['groups'], 'count'));
        $this->assertSame(7, array_sum(array_column($digest['groups'], 'count')));

        // and the rows really are under the right person
        $this->assertCount(3, $digest['groups'][0]['rows']);
        $this->assertCount(4, $digest['groups'][1]['rows']);
        $this->assertCount(7, $this->rows($digest));
    }

    /**
     * A telecaller gets their own rows in one unnamed group: a heading would
     * be their own name repeated down a list that is entirely theirs.
     *
     * The count proves the scoping. Seven tasks exist; three come back.
     */
    public function test_a_telecaller_sees_only_their_own(): void
    {
        $this->tasks($this->telecaller, 3);
        $this->tasks($this->salesperson, 4);

        $digest = $this->digest($this->telecaller);

        $this->assertSame(3, $digest['total']);
        $this->assertCount(1, $digest['groups']);
        $this->assertNull($digest['groups'][0]['name'], 'an own-list group carries no heading');
        $this->assertCount(3, $this->rows($digest));

        // every row is on a lead assigned to them, so nothing else leaked in
        $mine = Lead::where('assigned_to', $this->telecaller->id)->pluck('mobile_number')->all();
        foreach ($this->rows($digest) as $row) {
            $this->assertContains($row['mobile'], $mine);
        }
    }

    public function test_a_salesperson_sees_only_their_own(): void
    {
        $this->tasks($this->telecaller, 3);
        $this->tasks($this->salesperson, 4);

        $digest = $this->digest($this->salesperson);

        $this->assertSame(4, $digest['total']);
        $this->assertCount(1, $digest['groups']);
        $this->assertNull($digest['groups'][0]['name']);
        $this->assertCount(4, $this->rows($digest));

        $mine = Lead::where('assigned_to', $this->salesperson->id)->pluck('mobile_number')->all();
        foreach ($this->rows($digest) as $row) {
            $this->assertContains($row['mobile'], $mine);
        }
    }

    /* ---------------- what counts ---------------- */

    /** Nothing owed, nothing rendered. An empty notice is worse than none. */
    public function test_nothing_is_sent_when_nothing_is_due(): void
    {
        $this->assertNull($this->digest($this->admin));
    }

    /**
     * Yesterday's uncalled lead is the row this modal most needs to surface, so
     * the cut is "due today or earlier", not "due today".
     *
     * One list, not two. `earlier` exists only so the row can print its date;
     * both rows sit in the same group, in time order.
     */
    public function test_tasks_from_earlier_days_are_included_and_come_first(): void
    {
        $lead = $this->lead($this->telecaller);

        $this->todo($lead, Carbon::parse('2026-09-02 16:00'));   // later today
        $this->todo($lead, Carbon::parse('2026-08-30 10:00'));   // three days back

        $digest = $this->digest($this->telecaller);
        $rows   = $this->rows($digest);

        $this->assertSame(2, $digest['total']);
        $this->assertCount(1, $digest['groups'], 'an earlier day is not a second bucket');
        $this->assertTrue($rows[0]['earlier'], 'the oldest is listed first');
        $this->assertFalse($rows[1]['earlier']);
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
     * The list is capped at ten; the count is not. The line at the top has to
     * be the real total, and the shortfall is stated rather than left for the
     * reader to notice.
     */
    public function test_a_long_list_is_capped_and_the_remainder_is_stated(): void
    {
        $this->tasks($this->telecaller, 14);

        $digest = $this->digest($this->telecaller);

        $this->assertSame(14, $digest['total'], 'the count is everything owed');
        $this->assertSame(10, $digest['shown']);
        $this->assertSame(4, $digest['more'], '"and 4 more"');
        $this->assertCount(10, $this->rows($digest));
    }

    /** The cap is across the whole modal, not ten rows per person. */
    public function test_the_cap_is_across_every_group(): void
    {
        $this->tasks($this->telecaller, 8);
        $this->tasks($this->salesperson, 8);

        $digest = $this->digest($this->admin);

        $this->assertSame(16, $digest['total']);
        $this->assertSame(10, $digest['shown']);
        $this->assertSame(6, $digest['more']);
        $this->assertCount(10, $this->rows($digest));

        // the per-person count is the whole workload, not what survived the cap
        $this->assertSame([8, 8], array_column($digest['groups'], 'count'));
        $this->assertSame([8, 2], array_map(fn($g) => count($g['rows']), $digest['groups']));
    }

    /** Nobody appears as a heading with nothing listed under it. */
    public function test_no_group_comes_back_empty(): void
    {
        $this->tasks($this->telecaller, 12);
        $this->tasks($this->salesperson, 3);

        $digest = $this->digest($this->admin);

        $this->assertSame(['Priya Shah'], array_column($digest['groups'], 'name'),
            'the salesperson has no row inside the cap, so no heading either');

        foreach ($digest['groups'] as $group) {
            $this->assertNotEmpty($group['rows']);
        }
    }

    /** Every row carries what the modal prints, and nothing it does not. */
    public function test_each_row_carries_the_name_mobile_time_and_stage(): void
    {
        $lead = $this->lead($this->telecaller);
        $this->todo($lead, Carbon::parse('2026-09-02 16:00'));

        $row = $this->rows($this->digest($this->telecaller))[0];

        $this->assertSame('Meera Rani Sharma', $row['name']);
        $this->assertSame($lead->mobile_number, $row['mobile']);
        $this->assertSame('in_discussion', $row['stage']);
        $this->assertStringStartsWith('2026-09-02T16:00:00', $row['at']);
    }

    /* ---------------- once per session ---------------- */

    /** Shown on arrival, gone on every later visit in the same session. */
    public function test_the_modal_is_sent_once_per_session(): void
    {
        $this->tasks($this->telecaller, 2);

        $this->assertNotNull($this->digest($this->telecaller));
        $this->assertNull($this->digest($this->telecaller));
        $this->assertNull($this->digest($this->telecaller));
    }

    /**
     * Dismissing is a client-side hide over a server-side fact: by the time the
     * user closes the modal — Escape, the close button, the overlay, or
     * following the footer link — the session has already been marked, so the
     * next dashboard visit does not send it. Nothing has to be posted for that,
     * and this is the assertion that says so: no dismiss request is made here.
     */
    public function test_a_dismissed_modal_does_not_come_back_within_the_session(): void
    {
        $this->tasks($this->telecaller, 2);

        $this->digest($this->telecaller);            // shown, and closed on the page

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
     * "Once per session" is about the modal, not about the check. A user who
     * signs in to an empty list at nine should still be told about the task
     * that lands at ten.
     */
    public function test_an_empty_check_does_not_use_up_the_modal(): void
    {
        $this->assertNull($this->digest($this->telecaller));

        $this->tasks($this->telecaller, 1);

        $this->assertNotNull($this->digest($this->telecaller));
    }

    /**
     * Logging a call reloads cards, charts and followUps. The modal is not in
     * that list, so its closure is never invoked and the one showing this
     * session was owed is not burned by a background refresh.
     */
    public function test_a_partial_reload_neither_sends_nor_spends_the_modal(): void
    {
        $this->tasks($this->telecaller, 2);

        $partial = $this->actingAs($this->telecaller)->get('/dashboard', $this->inertia() + [
            'X-Inertia-Partial-Data'      => 'cards',
            'X-Inertia-Partial-Component' => 'Dashboard',
        ]);

        $partial->assertJsonMissingPath('props.todayDigest');

        $this->assertNotNull($this->digest($this->telecaller), 'the modal was still owed');
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

    /**
     * $n tasks due today, on $n leads, all belonging to $owner.
     *
     * Every task gets its own minute, across calls as well as within one. Rows
     * come back ordered by scheduled_at and the groups follow that order, so
     * fixtures sharing a timestamp would leave the order to the database.
     */
    private function tasks(User $owner, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->todo($this->lead($owner), now()->copy()->addMinutes(++$this->minute));
        }
    }

    /** Every listed row, flattened out of its group. */
    private function rows(array $digest): array
    {
        return collect($digest['groups'])->flatMap(fn($g) => $g['rows'])->all();
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
