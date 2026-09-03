<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The clamp, seen from the outside: what actually lands in `todos` when a call
 * is logged, and the one case that must never be clamped — the site visit the
 * customer chose.
 */
class WorkingHoursSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'first_name'    => 'Ann',
            'last_name'     => 'User',
            'email'         => 'admin@example.test',
            'mobile_number' => '9000000001',
            'role'          => 'admin',
            'is_active'     => true,
            'password'      => 'password',
        ]);
        $this->project = Project::create(['name' => 'Alpha']);

        config([
            'crm.working_hours' => ['start' => 9, 'end' => 18],
            'crm.working_days'  => [1, 2, 3, 4, 5, 6, 7],
            'crm.holidays'      => [],
        ]);
    }

    /** THE rule that must not be broken later. */
    public function test_a_customer_chosen_site_visit_at_8pm_on_a_sunday_is_saved_unchanged(): void
    {
        config(['crm.working_days' => [1, 2, 3, 4, 5]]);   // office shut at 8 PM Sunday
        Carbon::setTestNow(Carbon::parse('2026-09-02 10:00'));

        $todo = $this->pendingTodo();

        $this->actingAs($this->admin)->post("/todos/{$todo->id}/complete", [
            'remarks'  => 'Customer will come on Sunday evening.',
            'stage'    => 'site_visit_scheduled',
            'visit_at' => '2026-09-06 20:00',              // Sunday, 8 PM
        ])->assertRedirect()->assertSessionHasNoErrors();

        $visit = Todo::where('status', 'pending')->latest('id')->firstOrFail();

        $this->assertSame('2026-09-06 20:00', $visit->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('site_visit', $visit->type);
    }

    public function test_a_system_generated_follow_up_from_a_logged_call_is_clamped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 19:00'));   // Wednesday, after closing

        $todo = $this->pendingTodo();

        $this->actingAs($this->admin)->post("/todos/{$todo->id}/complete", [
            'remarks' => 'Visit done, sending options.',
            'stage'   => 'site_visit_done',                      // 24 hours
        ])->assertRedirect()->assertSessionHasNoErrors();

        $next = Todo::where('status', 'pending')->latest('id')->firstOrFail();

        // 7 PM + 24h = 7 PM tomorrow, past closing -> the morning after
        $this->assertSame('2026-09-04 09:00', $next->scheduled_at->format('Y-m-d H:i'));
    }

    /**
     * The over-correction guard, end to end: a result that already lands inside
     * working hours keeps its exact time and is not swept to the next opening.
     * WorkingHoursTest covers the same rule on the clamp itself, including the
     * 10 AM + 4h case.
     */
    public function test_a_result_already_inside_working_hours_keeps_its_exact_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 10:00'));

        $todo = $this->pendingTodo();

        $this->actingAs($this->admin)->post("/todos/{$todo->id}/complete", [
            'remarks' => 'Visit done, sending options.',
            'stage'   => 'site_visit_done',                      // 24 hours
        ])->assertRedirect();

        $next = Todo::where('status', 'pending')->latest('id')->firstOrFail();

        $this->assertSame('2026-09-03 10:00', $next->scheduled_at->format('Y-m-d H:i'));
    }

    public function test_a_lead_added_after_hours_is_called_the_next_morning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 21:00'));

        $this->actingAs($this->admin)->post('/leads', [
            'first_name'    => 'Neel',
            'last_name'     => 'Bhadani',
            'mobile_number' => '9876543210',
            'project_id'    => $this->project->id,
            'source'        => 'walk_in',
            'stage'         => 'fresh',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $todo = Todo::firstOrFail();

        $this->assertSame('2026-09-03 09:00', $todo->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_a_result_landing_on_a_holiday_moves_past_it(): void
    {
        config(['crm.holidays' => ['2026-09-03']]);
        Carbon::setTestNow(Carbon::parse('2026-09-02 11:00'));

        $todo = $this->pendingTodo();

        $this->actingAs($this->admin)->post("/todos/{$todo->id}/complete", [
            'remarks' => 'Sent the floor plan.',
            'stage'   => 'site_visit_done',                // 24 hours
        ])->assertRedirect();

        $next = Todo::where('status', 'pending')->latest('id')->firstOrFail();

        // 11 AM + 24h = 11 AM on the holiday -> 9 AM the day after
        $this->assertSame('2026-09-04 09:00', $next->scheduled_at->format('Y-m-d H:i'));
    }

    public function test_the_invariant_holds_across_a_run_of_clamped_calls(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 17:30'));

        $todo = $this->pendingTodo();

        foreach (['not_connected', 'not_connected', 'connected'] as $stage) {
            $current = Todo::where('status', 'pending')->latest('id')->firstOrFail();

            $this->actingAs($this->admin)->post("/todos/{$current->id}/complete", [
                'remarks' => 'Called.',
                'stage'   => $stage,
            ])->assertRedirect();

            $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
        }

        $this->assertSame(1, Todo::where('status', 'pending')->count());
        $this->assertNotNull($todo->fresh());
    }

    /* ---------------- the previews ---------------- */

    public function test_the_todo_page_sends_a_preview_for_every_stage(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 17:00'));
        $this->pendingTodo();

        $this->actingAs($this->admin)
            ->get('/todos?reset=1&tab=today')
            ->assertInertia(fn(Assert $page) => $page
                ->has('todos.data.0.follow_up_previews', count(config('crm.stages')))
                ->where('todos.data.0.follow_up_previews.not_connected',
                    'Next follow-up will be scheduled for 03 Sep 2026, 09:00 AM.')
                ->where('todos.data.0.follow_up_previews.booking_done',
                    'This closes the lead. No further follow-up will be scheduled.'));
    }

    /** The retry ladder is per lead, so the preview has to be too. */
    public function test_the_preview_follows_the_retry_ladder_of_that_lead(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 10:00'));

        $lead = $this->lead();
        $lead->update(['not_connected_count' => 2]);        // next retry is 48h, not 4h
        $this->pendingTodo($lead);

        $this->actingAs($this->admin)
            ->get('/todos?reset=1&tab=today')
            ->assertInertia(fn(Assert $page) => $page
                ->where('todos.data.0.follow_up_previews.not_connected',
                    'Next follow-up will be scheduled for 04 Sep 2026, 10:00 AM.'));
    }

    public function test_the_leads_page_sends_a_new_lead_preview_and_a_per_lead_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 21:00'));
        $this->lead();

        $this->actingAs($this->admin)
            ->get('/leads?reset=1')
            ->assertInertia(fn(Assert $page) => $page
                // adding: the first call is at opening, not "in 48 hours"
                ->where('options.followUpPreviews.connected',
                    'A follow-up call will be scheduled for 03 Sep 2026, 09:00 AM.')
                // editing an existing lead: the interval for that stage
                ->where('leads.data.0.follow_up_previews.connected',
                    'Next follow-up will be scheduled for 05 Sep 2026, 09:00 AM.'));
    }

    /* ---------------- fixtures ---------------- */

    private function lead(): Lead
    {
        return Lead::create([
            'first_name'    => 'Meera',
            'last_name'     => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id'    => $this->project->id,
            'source'        => 'walk_in',
            'stage'         => 'connected',
            'assigned_to'   => $this->admin->id,
            'created_by'    => $this->admin->id,
        ]);
    }

    private function pendingTodo(?Lead $lead = null): Todo
    {
        return Todo::create([
            'lead_id'      => ($lead ?? $this->lead())->id,
            'assigned_to'  => $this->admin->id,
            'created_by'   => $this->admin->id,
            'scheduled_at' => now(),
            'type'         => 'call',
            'status'       => 'pending',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
