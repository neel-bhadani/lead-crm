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
 * The Assigned to / Handled by column. The role has to survive the constrained
 * eager load — leaving it out of the select does not throw, it just arrives as
 * null and the sub-line renders empty — and hiding the column from non-admins
 * must stay presentation only: the row filtering is still scopeVisibleTo and
 * scopeForUser doing the work.
 */
class AssignedToColumnTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $telecaller;
    private User $salesperson;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin       = $this->user('admin', 'Rajesh', 'Mehta');
        $this->telecaller  = $this->user('telecaller', 'Tia', 'Shah');
        $this->salesperson = $this->user('salesperson', 'Sam', 'Rao');
        $this->project     = Project::create(['name' => 'Alpha']);
    }

    /* ---------------- the role reaches the page ---------------- */

    public function test_the_leads_page_sends_the_owner_role_and_the_labels(): void
    {
        $this->lead($this->telecaller);

        $this->actingAs($this->admin)
            ->get('/leads?reset=1')
            ->assertInertia(fn(Assert $page) => $page
                ->where('leads.data.0.owner.role', 'telecaller')
                ->where('leads.data.0.owner.display_name', 'Tia Shah')
                ->where('options.roleLabels', [
                    'admin'       => 'Admin',
                    'telecaller'  => 'Telecaller',
                    'salesperson' => 'Sales',
                ]));
    }

    public function test_the_todo_page_sends_the_owner_role_and_the_labels(): void
    {
        $this->todo($this->salesperson);

        $this->actingAs($this->admin)
            ->get('/todos?reset=1')
            ->assertInertia(fn(Assert $page) => $page
                ->where('todos.data.0.owner.role', 'salesperson')
                ->where('todos.data.0.owner.display_name', 'Sam Rao')
                ->where('options.roleLabels.salesperson', 'Sales'));
    }

    public function test_the_dashboard_panels_send_the_owner_role_and_the_labels(): void
    {
        $this->todo($this->telecaller);

        $this->dashboard('followUps')
            ->assertJsonPath('props.followUps.today.rows.0.owner.role', 'telecaller')
            ->assertJsonPath('props.followUps.today.rows.0.owner.display_name', 'Tia Shah');

        $this->dashboard('options')->assertJsonPath('props.options.roleLabels.admin', 'Admin');
    }

    /** display_name is an accessor over two columns; both are in the select. */
    public function test_display_name_survives_the_constrained_select_for_every_role(): void
    {
        foreach ([$this->admin, $this->telecaller, $this->salesperson] as $owner) {
            $this->lead($owner);
        }

        $this->actingAs($this->admin)
            ->get('/leads?reset=1')
            ->assertInertia(function (Assert $page) {
                foreach (range(0, 2) as $i) {
                    $page->has("leads.data.$i.owner.display_name")
                        ->has("leads.data.$i.owner.role")
                        ->where("leads.data.$i.owner.display_name", fn($v) => $v !== '' && $v !== null);
                }
            });
    }

    /* ---------------- hiding the column changes no data ---------------- */

    public function test_every_role_can_load_both_pages(): void
    {
        $this->todo($this->telecaller);
        $this->todo($this->salesperson);

        foreach ([$this->admin, $this->telecaller, $this->salesperson] as $user) {
            $this->actingAs($user)->get('/leads?reset=1')->assertOk();
            $this->actingAs($user)->get('/todos?reset=1')->assertOk();
        }
    }

    public function test_the_scopes_still_do_the_filtering_not_the_hidden_column(): void
    {
        $mine   = $this->lead($this->telecaller);
        $theirs = $this->lead($this->salesperson);

        $this->actingAs($this->telecaller)
            ->get('/leads?reset=1')
            ->assertInertia(fn(Assert $page) => $page
                ->has('leads.data', 1)
                ->where('leads.data.0.id', $mine->id));

        $this->actingAs($this->admin)
            ->get('/leads?reset=1')
            ->assertInertia(fn(Assert $page) => $page->has('leads.data', 2));

        $this->assertNotSame($mine->id, $theirs->id);
    }

    /* ---------------- the null case ---------------- */

    /**
     * A lead whose owner has been removed still sends a null owner, and the
     * column still renders the em dash — but for a different reason than it
     * used to, and the difference is the whole point of the user management
     * screen.
     *
     * `User` soft deletes now. The `nullOnDelete` on leads.assigned_to never
     * fires, because no DELETE ever reaches the database: the column keeps
     * pointing at the row, which is what preserves every per-person number on
     * the dashboard. What produces the null here is the relation — belongsTo
     * applies the related model's soft-delete scope, so a trashed user
     * resolves to null and the component gets exactly what it always got.
     *
     * In practice an *open* lead never reaches this state: deleting a user
     * runs a handover first, so their open leads have already gone to somebody
     * else or been deliberately unassigned. This is the historical case — a
     * closed lead still recording who worked it.
     */
    public function test_a_lead_whose_assigned_user_was_deleted_sends_a_null_owner(): void
    {
        $lead = $this->lead($this->salesperson);

        $this->salesperson->delete();

        $this->assertSoftDeleted('users', ['id' => $this->salesperson->id]);

        // the column survives — this is the history the charts are counted from
        $this->assertSame($this->salesperson->id, $lead->fresh()->assigned_to);

        // and the relation still resolves to nothing, so the cell is an em dash
        $this->assertNull($lead->fresh()->owner);

        $this->actingAs($this->admin)
            ->get('/leads?reset=1')
            ->assertOk()
            ->assertInertia(fn(Assert $page) => $page
                ->has('leads.data', 1)
                ->where('leads.data.0.owner', null));
    }

    /* ---------------- fixtures ---------------- */

    private function user(string $role, string $first, string $last): User
    {
        return User::create([
            'first_name'    => $first,
            'last_name'     => $last,
            'email'         => "$role@example.test",
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role'          => $role,
            'is_active'     => true,
            'password'      => 'password',
        ]);
    }

    private function lead(User $owner): Lead
    {
        return Lead::create([
            'first_name'    => 'Meera',
            'last_name'     => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id'    => $this->project->id,
            'source'        => 'walk_in',
            'stage'         => 'connected',
            'assigned_to'   => $owner->id,
            'created_by'    => $this->admin->id,
        ]);
    }

    private function todo(User $owner): Todo
    {
        return Todo::create([
            'lead_id'      => $this->lead($owner)->id,
            'assigned_to'  => $owner->id,
            'created_by'   => $this->admin->id,
            'scheduled_at' => today()->setTime(10, 0),
            'type'         => 'call',
            'status'       => 'pending',
        ]);
    }

    private function dashboard(string $only)
    {
        return $this->actingAs($this->admin)->withHeaders([
            'X-Inertia'                   => 'true',
            'X-Inertia-Version'           => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Data'      => $only,
            'X-Inertia-Partial-Component' => 'Dashboard',
        ])->get('/dashboard');
    }
}
