<?php

namespace Tests\Feature;

use App\Http\Controllers\TodoController;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The follow-up clash warning.
 *
 * The whole feature is one sentence shown before saving, and the thing most
 * worth pinning is what it does NOT do:
 *
 *   it never refuses   there is no validation rule reading the clash window,
 *                      on any of the four save paths. Every test that provokes
 *                      a clash also saves through it and asserts the save
 *                      succeeded — a warning that blocks is a bug, not a
 *                      stricter warning.
 *
 *   it never lies      the site-visit handover moves the follow-up to a
 *                      salesperson picked on save, so the receiving person is
 *                      unknowable beforehand. The forms stay silent there
 *                      rather than naming the telecaller who typed the date.
 *
 *   it never leaks     the clashing customer's name is printed only to somebody
 *                      who could open that lead anyway. Everyone else gets the
 *                      time and the type, which is all they need to pick
 *                      another slot.
 *
 * @see TodoController::checkConflict()
 * @see TodoController::clashSentence()
 */
class FollowUpConflictTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/follow-ups/check-conflict';

    private User $admin;

    private User $priya;

    private User $sam;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // a fixed Wednesday afternoon in IST, so "3:20 PM" is the same string
        // in the assertion as it is on the screen
        Carbon::setTestNow(Carbon::parse('2026-09-09 10:00', 'Asia/Kolkata'));

        $this->admin = $this->user('admin', 'Ann', 'Admin');
        $this->priya = $this->user('telecaller', 'Priya', 'Shah');
        $this->sam = $this->user('salesperson', 'Sam', 'Rao');
        $this->project = Project::create(['name' => 'Alpha']);
    }

    /* ---------------- what counts as a clash ---------------- */

    public function test_a_clear_diary_returns_nothing(): void
    {
        $this->existing($this->priya, '2026-09-09 15:20');

        $this->check($this->priya, '2026-09-09 18:00')
            ->assertOk()
            ->assertJson(['conflict' => null]);
    }

    public function test_a_follow_up_at_the_same_time_clashes(): void
    {
        $this->existing($this->priya, '2026-09-09 15:20');

        $this->check($this->priya, '2026-09-09 15:20')
            ->assertOk()
            ->assertJsonPath('conflict.count', 1);
    }

    /**
     * Thirty minutes either side, from config, and the boundary is inclusive.
     *
     * @param  string  $at  the time being picked
     * @param  bool  $expected  whether the 3:20 PM follow-up should be reported
     */
    #[DataProvider('windowEdges')]
    public function test_the_window_is_thirty_minutes_either_side(string $at, bool $expected): void
    {
        $this->existing($this->priya, '2026-09-09 15:20');

        $response = $this->check($this->priya, $at)->assertOk();

        $expected
            ? $response->assertJsonPath('conflict.count', 1)
            : $response->assertJson(['conflict' => null]);
    }

    public static function windowEdges(): array
    {
        return [
            '29 minutes before' => ['2026-09-09 14:51', true],
            'exactly 30 before' => ['2026-09-09 14:50', true],
            '31 minutes before' => ['2026-09-09 14:49', false],
            '29 minutes after' => ['2026-09-09 15:49', true],
            'exactly 30 after' => ['2026-09-09 15:50', true],
            '31 minutes after' => ['2026-09-09 15:51', false],
        ];
    }

    /** The window is one number the client can change, and nothing else reads it. */
    public function test_the_window_comes_from_config(): void
    {
        config(['crm.follow_up_clash_minutes' => 5]);

        $this->existing($this->priya, '2026-09-09 15:20');

        $this->check($this->priya, '2026-09-09 15:40')->assertJson(['conflict' => null]);
        $this->check($this->priya, '2026-09-09 15:24')->assertJsonPath('conflict.count', 1);
    }

    public function test_completed_and_cancelled_follow_ups_are_not_clashes(): void
    {
        $this->existing($this->priya, '2026-09-09 15:20', status: 'completed');
        $this->existing($this->priya, '2026-09-09 15:25', status: 'cancelled');

        $this->check($this->priya, '2026-09-09 15:20')->assertJson(['conflict' => null]);
    }

    public function test_another_users_follow_up_is_not_a_clash(): void
    {
        $this->existing($this->sam, '2026-09-09 15:20');

        $this->check($this->priya, '2026-09-09 15:20')->assertJson(['conflict' => null]);
    }

    /** A follow-up on a deleted lead is on no list, so it is nothing to warn about. */
    public function test_a_follow_up_on_a_deleted_lead_is_not_a_clash(): void
    {
        $todo = $this->existing($this->priya, '2026-09-09 15:20');
        $todo->lead->delete();

        $this->check($this->priya, '2026-09-09 15:20')->assertJson(['conflict' => null]);
    }

    /* ---------------- the sentence ---------------- */

    public function test_it_names_the_person_the_type_the_lead_and_the_time(): void
    {
        $this->existing($this->priya, '2026-09-09 15:20', lead: 'Meera Vaghela', type: 'call');

        $this->check($this->priya, '2026-09-09 15:30')
            ->assertJsonPath(
                'conflict.message',
                'Priya Shah already has a call with Meera Vaghela at 3:20 PM.'
            );
    }

    /** The nearest one is the one named; the rest are counted. */
    public function test_more_than_one_clash_says_and_two_others(): void
    {
        $this->existing($this->priya, '2026-09-09 15:20', lead: 'Meera Vaghela');
        $this->existing($this->priya, '2026-09-09 15:05', lead: 'Rohit Shah');
        $this->existing($this->priya, '2026-09-09 15:45', lead: 'Anita Desai');

        $this->check($this->priya, '2026-09-09 15:25')
            ->assertJsonPath(
                'conflict.message',
                'Priya Shah already has a call with Meera Vaghela at 3:20 PM, and 2 others.'
            )
            ->assertJsonPath('conflict.count', 3);
    }

    public function test_a_single_other_is_not_pluralised(): void
    {
        $this->existing($this->priya, '2026-09-09 15:20', lead: 'Meera Vaghela');
        $this->existing($this->priya, '2026-09-09 15:40', lead: 'Rohit Shah');

        $this->check($this->priya, '2026-09-09 15:25')
            ->assertJsonPath(
                'conflict.message',
                'Priya Shah already has a call with Meera Vaghela at 3:20 PM, and 1 other.'
            );
    }

    public function test_the_type_reads_as_written_in_config(): void
    {
        $this->existing($this->priya, '2026-09-09 15:20', lead: 'Meera Vaghela', type: 'site_visit');
        $this->check($this->priya, '2026-09-09 15:25')
            ->assertJsonPath(
                'conflict.message',
                'Priya Shah already has a site visit with Meera Vaghela at 3:20 PM.'
            );

        Todo::query()->update(['type' => 'whatsapp']);

        // a proper noun keeps its capitals: "a whatsapp" would read as a typo
        $this->check($this->priya, '2026-09-09 15:25')
            ->assertJsonPath(
                'conflict.message',
                'Priya Shah already has a WhatsApp with Meera Vaghela at 3:20 PM.'
            );
    }

    /* ---------------- the name is the thing that is protected ---------------- */

    public function test_a_user_who_cannot_see_the_lead_gets_the_time_but_not_the_name(): void
    {
        $this->existing($this->sam, '2026-09-09 15:20', lead: 'Meera Vaghela');

        // Priya is a telecaller: Sam's leads are not hers to see
        $this->actingAs($this->priya)
            ->postJson(self::URL, [
                'assigned_to' => $this->sam->id,
                'scheduled_at' => '2026-09-09 15:25',
            ])
            ->assertOk()
            ->assertJsonPath('conflict.message', 'Sam Rao already has a call at 3:20 PM.')
            ->assertJsonMissing(['message' => 'Sam Rao already has a call with Meera Vaghela at 3:20 PM.']);
    }

    public function test_an_admin_sees_the_name_on_anybody_s_follow_up(): void
    {
        $this->existing($this->sam, '2026-09-09 15:20', lead: 'Meera Vaghela');

        $this->actingAs($this->admin)
            ->postJson(self::URL, [
                'assigned_to' => $this->sam->id,
                'scheduled_at' => '2026-09-09 15:25',
            ])
            ->assertJsonPath('conflict.message', 'Sam Rao already has a call with Meera Vaghela at 3:20 PM.');
    }

    /** A manager with see_all_leads is not an admin, but the leads are hers to read. */
    public function test_see_all_leads_is_enough_to_be_told_the_name(): void
    {
        $this->existing($this->sam, '2026-09-09 15:20', lead: 'Meera Vaghela');

        $this->priya->update(['permissions' => ['see_all_leads' => true]]);

        $this->actingAs($this->priya)
            ->postJson(self::URL, [
                'assigned_to' => $this->sam->id,
                'scheduled_at' => '2026-09-09 15:25',
            ])
            ->assertJsonPath('conflict.message', 'Sam Rao already has a call with Meera Vaghela at 3:20 PM.');
    }

    public function test_a_guest_cannot_ask(): void
    {
        $this->postJson(self::URL, [
            'assigned_to' => $this->priya->id,
            'scheduled_at' => '2026-09-09 15:25',
        ])->assertUnauthorized();
    }

    /* ---------------- rescheduling does not clash with itself ---------------- */

    public function test_the_excluded_follow_up_is_not_reported(): void
    {
        $todo = $this->existing($this->priya, '2026-09-09 15:20');

        $this->check($this->priya, '2026-09-09 15:25')->assertJsonPath('conflict.count', 1);

        $this->actingAs($this->admin)
            ->postJson(self::URL, [
                'assigned_to' => $this->priya->id,
                'scheduled_at' => '2026-09-09 15:25',
                'exclude_todo_id' => $todo->id,
            ])
            ->assertJson(['conflict' => null]);
    }

    /**
     * The reschedule form's own round trip: open the task, move it twenty
     * minutes, and be told nothing — the only follow-up in that window is the
     * one being moved.
     */
    public function test_rescheduling_a_follow_up_does_not_warn_against_itself(): void
    {
        $todo = $this->existing($this->priya, '2026-09-10 15:20');

        $this->actingAs($this->priya)
            ->postJson(self::URL, [
                'assigned_to' => $todo->assigned_to,
                'scheduled_at' => '2026-09-10 15:40',
                'exclude_todo_id' => $todo->id,
            ])
            ->assertJson(['conflict' => null]);

        // and the reschedule itself goes through
        $this->actingAs($this->priya)
            ->put("/todos/{$todo->id}", [
                'lead_id' => $todo->lead_id,
                'type' => 'call',
                'scheduled_at' => '2026-09-10 15:40',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-09-10 15:40:00', $todo->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /* ---------------- a warning, never a refusal ---------------- */

    public function test_adding_a_lead_with_a_clashing_follow_up_saves(): void
    {
        $this->existing($this->priya, '2026-09-10 15:20');

        $this->actingAs($this->admin)
            ->post('/leads', [
                'first_name' => 'Neel',
                'last_name' => 'Bhadani',
                'mobile_number' => '9512779297',
                'project_id' => $this->project->id,
                'source' => 'walk_in',
                'stage' => 'fresh',
                'follow_up_type' => 'call',
                'follow_up_at' => '2026-09-10 15:25',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $lead = Lead::where('mobile_number', '9512779297')->firstOrFail();

        $this->assertSame($this->priya->id, $lead->assigned_to);
        $this->assertSame('2026-09-10 15:25:00', $lead->pendingTodo->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_logging_a_call_with_a_clashing_next_follow_up_saves(): void
    {
        $busy = $this->existing($this->priya, '2026-09-10 15:20');
        $todo = $this->existing($this->priya, '2026-09-09 11:00', lead: 'Rohit Shah');

        $this->actingAs($this->priya)
            ->post("/todos/{$todo->id}/complete", [
                'remarks' => 'Spoke to the customer.',
                'stage' => 'connected',
                'follow_up_type' => 'call',
                'follow_up_at' => '2026-09-10 15:25',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $todo->fresh()->status);
        $this->assertSame('pending', $busy->fresh()->status);

        // one pending task per lead is untouched: this is a clash ACROSS leads
        $this->assertSame(1, Todo::where('lead_id', $todo->lead_id)->where('status', 'pending')->count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_adding_a_follow_up_by_hand_at_a_clashing_time_saves(): void
    {
        $this->existing($this->priya, '2026-09-10 15:20');

        $lead = $this->lead('Anita Desai', $this->priya);

        $this->actingAs($this->priya)
            ->post('/todos', [
                'lead_id' => $lead->id,
                'type' => 'call',
                'scheduled_at' => '2026-09-10 15:25',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Todo::where('lead_id', $lead->id)->where('status', 'pending')->count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_rescheduling_onto_somebody_elses_slot_saves(): void
    {
        $this->existing($this->priya, '2026-09-10 15:20');
        $todo = $this->existing($this->priya, '2026-09-11 09:00', lead: 'Rohit Shah');

        $this->actingAs($this->priya)
            ->put("/todos/{$todo->id}", [
                'lead_id' => $todo->lead_id,
                'type' => 'call',
                'scheduled_at' => '2026-09-10 15:25',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /* ---------------- what the four forms are given to ask with ---------------- */

    /**
     * The add-lead modal has to name the person a NEW lead would go to, and
     * only the server knows who that is. `defaultOwners` is that answer, per
     * project and stage because both decide it, and it has to be the same one
     * store() acts on or the warning names a stranger.
     */
    public function test_the_leads_page_ships_the_owner_a_new_lead_would_go_to(): void
    {
        $owners = "options.defaultOwners.{$this->project->id}";

        // a fresh lead goes to the telecaller whoever adds it; one past the
        // calling stage to a salesperson; a closed one stays with the creator
        $this->actingAs($this->admin)
            ->get('/leads')
            ->assertInertia(fn (Assert $page) => $page
                ->where("$owners.fresh", $this->priya->id)
                ->where("$owners.site_visit_done", $this->sam->id)
                ->where("$owners.booking_done", $this->admin->id));

        $this->actingAs($this->sam)
            ->get('/leads')
            ->assertInertia(fn (Assert $page) => $page
                ->where("$owners.fresh", $this->priya->id)
                ->where("$owners.site_visit_done", $this->sam->id)
                ->where("$owners.booking_done", $this->sam->id));
    }

    /**
     * Editing a lead's stage cancels the task it is holding and writes a new
     * one, so the form excludes the old row from its own check. It can only do
     * that if the row's id is on the lead it was given.
     */
    public function test_the_leads_page_ships_the_pending_follow_up_it_would_replace(): void
    {
        $todo = $this->existing($this->priya, '2026-09-10 15:20');

        $this->actingAs($this->admin)
            ->get('/leads')
            ->assertInertia(fn (Assert $page) => $page
                ->where('leads.data.0.pending_todo.id', $todo->id)
                ->where('leads.data.0.assigned_to', $this->priya->id));
    }

    public function test_the_todo_page_ships_the_owner_of_each_schedulable_lead(): void
    {
        $lead = $this->lead('Anita Desai', $this->priya);

        $this->actingAs($this->admin)
            ->get('/todos')
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.openLeads.0.id', $lead->id)
                ->where('options.openLeads.0.assigned_to', $this->priya->id));
    }

    /**
     * The log-call modal decides whether to stay quiet from the lead's own
     * assigned_role, so both pages that open it have to send it.
     */
    public function test_the_todo_and_dashboard_rows_carry_the_handover_facts(): void
    {
        $todo = $this->existing($this->priya, '2026-09-09 15:20');

        $this->actingAs($this->priya)
            ->get('/todos?tab=today')
            ->assertInertia(fn (Assert $page) => $page
                ->where('todos.data.0.lead.assigned_to', $this->priya->id)
                ->where('todos.data.0.lead.assigned_role', 'telecaller'));

        $this->assertSame($todo->id, Todo::firstOrFail()->id);

        $this->actingAs($this->priya)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('followUps.today.rows.0.lead.assigned_to', $this->priya->id)
                ->where('followUps.today.rows.0.lead.assigned_role', 'telecaller'));
    }

    /* ---------------- input ---------------- */

    public function test_a_missing_or_unusable_input_is_a_422_and_says_nothing(): void
    {
        $this->actingAs($this->admin)->postJson(self::URL, [])->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson(self::URL, ['assigned_to' => 99999, 'scheduled_at' => '2026-09-09 15:25'])
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson(self::URL, ['assigned_to' => $this->admin->id, 'scheduled_at' => 'not a date'])
            ->assertStatus(422);
    }

    /* ---------------- fixtures ---------------- */

    private function check(User $owner, string $at)
    {
        return $this->actingAs($this->admin)->postJson(self::URL, [
            'assigned_to' => $owner->id,
            'scheduled_at' => $at,
        ]);
    }

    /** A lead with a pending follow-up already on somebody's diary. */
    private function existing(
        User $owner,
        string $at,
        string $lead = 'Meera Vaghela',
        string $type = 'call',
        string $status = 'pending',
    ): Todo {
        return Todo::create([
            'lead_id' => $this->lead($lead, $owner)->id,
            'assigned_to' => $owner->id,
            'created_by' => $this->admin->id,
            'scheduled_at' => $at,
            'type' => $type,
            'status' => $status,
        ]);
    }

    private function lead(string $name, User $owner): Lead
    {
        [$first, $last] = explode(' ', $name, 2);

        return Lead::create([
            'first_name' => $first,
            'last_name' => $last,
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $this->project->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            'assigned_to' => $owner->id,
            'assigned_role' => $owner->role,
            'created_by' => $this->admin->id,
        ]);
    }

    private function user(string $role, string $first, string $last): User
    {
        return User::create([
            'first_name' => $first,
            'last_name' => $last,
            'email' => fake()->unique()->safeEmail(),
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role,
            'is_active' => true,
            'password' => 'password',
        ]);
    }
}
