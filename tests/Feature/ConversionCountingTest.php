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
 * Booking and Lost count events, not intake.
 *
 * They used to be filtered by leads.created_at, so a lead created 40 days ago
 * and booked today was missing from every short range — the booking's own date
 * was never consulted. They now read the stage history, which means the history
 * has to be complete: every path that moves a stage writes a row.
 */
class ConversionCountingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $telecaller;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin      = $this->user('admin');
        $this->telecaller = $this->user('telecaller');
        $this->project    = Project::create(['name' => 'Alpha']);

        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00'));
    }

    /* ---------------- the reported bug ---------------- */

    public function test_a_lead_created_40_days_ago_and_booked_today_counts_in_every_range(): void
    {
        $lead = $this->lead($this->admin, now()->subDays(40));

        $this->service()->changeStage($lead, 'booking_done', ['booked_unit' => 'A-1201']);

        foreach (['today' => 1, '7' => 1, '30' => 1] as $range => $expected) {
            $this->assertSame(
                $expected,
                $this->card($range, 'booked'),
                "range=$range should count a booking made today"
            );
        }
    }

    public function test_a_booking_made_before_the_range_is_not_counted(): void
    {
        $lead = $this->lead($this->admin, now()->subDays(40));

        // 20 Aug: thirteen days back, so inside the 30-day range and outside the 7
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00'));
        $this->service()->changeStage($lead, 'booking_done', ['booked_unit' => 'A-1']);
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00'));

        $this->assertSame(0, $this->card('today', 'booked'));
        $this->assertSame(0, $this->card('7', 'booked'));
        $this->assertSame(1, $this->card('30', 'booked'));
    }

    public function test_lost_is_counted_the_same_way(): void
    {
        $lead = $this->lead($this->admin, now()->subDays(40));

        $this->service()->changeStage($lead, 'lost', ['reason' => 'budget']);

        $this->assertSame(1, $this->card('today', 'lost'));
    }

    /** One lead booking twice in a range is still one booking. */
    public function test_a_lead_is_never_double_counted(): void
    {
        $lead = $this->lead($this->admin, now()->subDays(40));

        $this->service()->changeStage($lead, 'booking_done', ['booked_unit' => 'A-1']);
        $this->service()->changeStage($lead->fresh(), 'in_discussion');
        $this->service()->changeStage($lead->fresh(), 'booking_done', ['booked_unit' => 'A-1']);

        $this->assertSame(2, Todo::where('outcome_stage', 'booking_done')->count());
        $this->assertSame(1, $this->card('today', 'booked'));
    }

    /* ---------------- the history gap ---------------- */

    public function test_a_stage_change_from_the_lead_form_writes_history(): void
    {
        $lead = $this->lead($this->admin, now()->subDays(5));

        $this->service()->changeStage($lead, 'site_visit_done');

        $row = Todo::where('outcome_stage', 'site_visit_done')->firstOrFail();

        $this->assertSame('completed', $row->status);
        $this->assertNotNull($row->completed_at);
        $this->assertSame($lead->id, $row->lead_id);
    }

    public function test_a_lead_created_straight_into_a_terminal_stage_writes_history(): void
    {
        $this->actingAs($this->admin)->post('/leads', [
            'first_name'    => 'Backfill',
            'last_name'     => 'Booking',
            'mobile_number' => '9876500001',
            'project_id'    => $this->project->id,
            'source'        => 'walk_in',
            'stage'         => 'booking_done',
            'booked_unit'   => 'B-701',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $this->card('today', 'booked'));
        $this->assertSame(0, Todo::where('status', 'pending')->count());
    }

    /** A non-terminal creation must NOT fabricate a transition. */
    public function test_a_normal_new_lead_writes_no_history_row(): void
    {
        $this->actingAs($this->admin)->post('/leads', [
            'first_name'    => 'Ordinary',
            'last_name'     => 'Lead',
            'mobile_number' => '9876500002',
            'project_id'    => $this->project->id,
            'source'        => 'walk_in',
            'stage'         => 'fresh',
            'follow_up_type' => 'call',
            'follow_up_at'   => now()->addDay()->format('Y-m-d H:i'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, Todo::whereNotNull('outcome_stage')->count());
        $this->assertSame(1, Todo::where('status', 'pending')->count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /**
     * The retry ladder used to close a lead at the fifth failed attempt. It
     * depended on the interval table and went with it, so nothing acts on
     * `not_connected_count` any more — it is counted, printed beside the lead
     * as an attempt number, and left alone.
     *
     * A lead is lost when a user picks Lost, and at no other moment.
     */
    public function test_repeated_no_answers_never_close_the_lead_by_themselves(): void
    {
        $lead = $this->lead($this->admin, now()->subDays(10));
        $lead->update(['stage' => 'not_connected', 'not_connected_count' => 4]);

        $todo = Todo::create([
            'lead_id' => $lead->id, 'assigned_to' => $this->admin->id,
            'created_by' => $this->admin->id, 'scheduled_at' => now(),
            'type' => 'call', 'status' => 'pending',
        ]);

        $next = now()->addDay()->startOfMinute();

        $this->service()->complete($todo, 'not_connected', 'No answer again.', $next, 'call');

        $lead->refresh();

        $this->assertSame('not_connected', $lead->stage, 'nothing may close a lead on its own');
        $this->assertSame(5, $lead->not_connected_count, 'the attempt number is still counted');
        $this->assertSame(0, $this->card('today', 'lost'));

        // and the lead is still on somebody's list, at the moment they chose
        $pending = Todo::where('lead_id', $lead->id)->where('status', 'pending')->get();

        $this->assertCount(1, $pending);
        $this->assertSame($next->format('Y-m-d H:i'), $pending->first()->scheduled_at->format('Y-m-d H:i'));
    }

    /* ---------------- conversion is a cohort ---------------- */

    public function test_conversion_can_never_exceed_one_hundred_percent(): void
    {
        // three bookings from old leads, two new leads that have not booked
        foreach (range(1, 3) as $i) {
            $this->service()->changeStage($this->lead($this->admin, now()->subDays(90)), 'booking_done', ['booked_unit' => "A-$i"]);
        }
        $this->lead($this->admin, now());
        $this->lead($this->admin, now());

        $this->assertSame(3, $this->card('today', 'booked'));
        $this->assertSame(2, $this->card('today', 'total'));
        // the old booked/total would have read 150%
        $this->assertEquals(0, $this->card('today', 'conversion'));
    }

    public function test_conversion_counts_a_new_lead_that_has_since_booked(): void
    {
        $booked = $this->lead($this->admin, now());
        $this->lead($this->admin, now());
        $this->lead($this->admin, now());
        $this->lead($this->admin, now());

        $this->service()->changeStage($booked, 'booking_done', ['booked_unit' => 'A-1']);

        $this->assertSame(4, $this->card('today', 'total'));
        $this->assertEquals(25, $this->card('today', 'conversion'));
    }

    /** A zero denominator is a dash, never 0% and never an error. */
    public function test_conversion_is_null_when_no_leads_were_created(): void
    {
        $this->service()->changeStage($this->lead($this->admin, now()->subDays(90)), 'booking_done', ['booked_unit' => 'A-1']);

        $this->assertSame(1, $this->card('today', 'booked'));
        $this->assertNull($this->card('today', 'conversion'));
    }

    /* ---------------- visibility ---------------- */

    public function test_a_telecaller_only_counts_conversions_on_leads_they_can_see(): void
    {
        $mine   = $this->lead($this->telecaller, now()->subDays(40));
        $theirs = $this->lead($this->admin, now()->subDays(40));

        $this->service()->changeStage($mine, 'booking_done', ['booked_unit' => 'A-1']);
        $this->service()->changeStage($theirs, 'booking_done', ['booked_unit' => 'A-2']);

        $this->assertSame(2, $this->card('today', 'booked', $this->admin));
        $this->assertSame(1, $this->card('today', 'booked', $this->telecaller));
    }

    /* ---------------- intake cards are untouched ---------------- */

    public function test_total_and_today_still_count_by_creation(): void
    {
        $this->lead($this->admin, now());
        $this->lead($this->admin, now()->subDays(3));
        $this->lead($this->admin, now()->subDays(40));

        $this->assertSame(1, $this->card('today', 'total'));
        $this->assertSame(2, $this->card('7', 'total'));
        $this->assertSame(1, $this->card('today', 'today'));
    }

    /* ---------------- helpers ---------------- */

    private function service(): LeadFollowUpService
    {
        $this->actingAs($this->admin);

        return app(LeadFollowUpService::class);
    }

    private function card(string $range, string $key, ?User $as = null)
    {
        $res = $this->actingAs($as ?? $this->admin)->withHeaders([
            'X-Inertia'                   => 'true',
            'X-Inertia-Version'           => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Data'      => 'cards',
            'X-Inertia-Partial-Component' => 'Dashboard',
        ])->get("/dashboard?reset=1&range=$range");

        return $res->json("props.cards.$key");
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

    private function lead(User $owner, Carbon $createdAt): Lead
    {
        $lead = Lead::create([
            'first_name' => 'Meera', 'last_name' => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $this->project->id, 'source' => 'walk_in',
            'stage' => 'in_discussion', 'assigned_to' => $owner->id,
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
