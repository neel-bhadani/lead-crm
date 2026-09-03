<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A deleted lead used to take the To-do page with it.
 *
 * `Lead` soft deletes and `LeadController::destroy()` keeps the completed
 * todos as history, so those rows outlived their lead with a `lead` relation
 * resolving to null — and `t.lead.full_name` white-screened the page. The fix is
 * Todo::hasLead(): a deleted lead's rows leave every list at once, and come
 * back if the lead is restored.
 */
class OrphanedTodoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin   = User::create([
            'first_name'    => 'Admin',
            'last_name'     => 'User',
            'email'         => 'admin@example.test',
            'mobile_number' => '9000000001',
            'role'          => 'admin',
            'is_active'     => true,   // the role middleware checks this
            'password'      => 'password',
        ]);
        $this->project = Project::create(['name' => 'Green Acres']);
    }

    public function test_deleting_a_lead_with_a_completed_todo_leaves_both_pages_standing(): void
    {
        $lead = $this->lead();
        $this->completed($lead);

        $this->actingAs($this->admin)
            ->delete("/leads/{$lead->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('leads', ['id' => $lead->id]);

        // the history row is still there — it is just not on the page any more
        $this->assertDatabaseHas('todos', ['lead_id' => $lead->id, 'status' => 'completed']);

        foreach (['overdue', 'today', 'upcoming', 'completed'] as $tab) {
            $this->actingAs($this->admin)
                ->get("/todos?reset=1&tab=$tab")
                ->assertOk()
                ->assertInertia(fn(Assert $page) => $page
                    ->where('tab', $tab)
                    ->has('todos.data', 0)
                    ->where('counts.completed', 0));
        }

        $this->dashboardVisit()->assertOk()->assertJsonPath('props.cards.pending', 0);
    }

    public function test_an_orphaned_row_is_gone_from_every_tab_count_and_from_the_panels(): void
    {
        $kept    = $this->lead('Meera');
        $deleted = $this->lead('Rahul');

        $this->completed($kept);
        $this->completed($deleted);
        $this->overdue($deleted);

        $deleted->delete();

        $this->actingAs($this->admin)
            ->get('/todos?reset=1&tab=completed')
            ->assertInertia(fn(Assert $page) => $page
                ->has('todos.data', 1)
                ->where('todos.data.0.lead.id', $kept->id)
                // the badge has to agree with the rows below it
                ->where('counts.completed', 1)
                ->where('counts.overdue', 0));

        $this->dashboardVisit('followUps')
            ->assertJsonPath('props.followUps.overdue.total', 0)
            ->assertJsonPath('props.followUps.overdue.rows', []);
    }

    public function test_restoring_the_lead_brings_its_history_back(): void
    {
        $lead = $this->lead();
        $this->completed($lead);

        $lead->delete();
        $lead->restore();

        $this->actingAs($this->admin)
            ->get('/todos?reset=1&tab=completed')
            ->assertInertia(fn(Assert $page) => $page
                ->has('todos.data', 1)
                ->where('counts.completed', 1));
    }

    /**
     * The constrained eager load has to carry the columns the appended
     * days_in_stage accessor reads. Leaving them out never threw — it just
     * shipped null, which reads as "unknown" rather than as a number.
     */
    public function test_the_eager_loaded_lead_carries_a_real_days_in_stage(): void
    {
        $lead = $this->lead();
        $lead->update(['stage_changed_at' => now()->subDays(5)]);
        $this->overdue($lead);

        $this->actingAs($this->admin)
            ->get('/todos?reset=1&tab=overdue')
            ->assertInertia(fn(Assert $page) => $page
                ->where('todos.data.0.lead.days_in_stage', 5)
                ->where('todos.data.0.lead.id', $lead->id));

        $this->dashboardVisit('followUps')
            ->assertJsonPath('props.followUps.overdue.rows.0.lead.days_in_stage', 5);
    }

    public function test_the_scheduler_invariant_still_holds_after_a_delete(): void
    {
        $lead = $this->lead();
        $this->completed($lead);

        $this->actingAs($this->admin)->delete("/leads/{$lead->id}");

        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /* ---------------- fixtures ---------------- */

    private function lead(string $name = 'Meera'): Lead
    {
        return Lead::create([
            'first_name'    => $name,
            'last_name'     => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id'    => $this->project->id,
            'source'        => 'walk_in',
            'stage'         => 'connected',
            'assigned_to'   => $this->admin->id,
            'created_by'    => $this->admin->id,
        ]);
    }

    private function completed(Lead $lead): Todo
    {
        return Todo::create([
            'lead_id'       => $lead->id,
            'assigned_to'   => $this->admin->id,
            'created_by'    => $this->admin->id,
            'scheduled_at'  => today()->subDay()->setTime(10, 0),
            'type'          => 'call',
            'status'        => 'completed',
            'completed_at'  => now(),
            'completed_by'  => $this->admin->id,
            'outcome_stage' => 'connected',
        ]);
    }

    private function overdue(Lead $lead): Todo
    {
        return Todo::create([
            'lead_id'      => $lead->id,
            'assigned_to'  => $this->admin->id,
            'created_by'   => $this->admin->id,
            'scheduled_at' => today()->subDays(3)->setTime(10, 0),
            'type'         => 'call',
            'status'       => 'pending',
        ]);
    }

    /** Partial visits: the chart queries use MySQL date functions sqlite lacks. */
    private function dashboardVisit(string $only = 'cards')
    {
        return $this->actingAs($this->admin)->withHeaders([
            'X-Inertia'                   => 'true',
            'X-Inertia-Version'           => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Data'      => $only,
            'X-Inertia-Partial-Component' => 'Dashboard',
        ])->get('/dashboard');
    }
}
