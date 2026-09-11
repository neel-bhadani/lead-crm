<?php

namespace Tests\Feature;

use App\Http\Controllers\ProjectController;
use App\Models\Alert;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Projects screen: the door, the arithmetic, and the delete that must not
 * happen.
 *
 * THE DELETE is the reason this file exists. `leads.project_id` is a foreign
 * key with cascadeOnDelete, so a hard DELETE on a project takes every lead
 * filed against it and every follow-up hanging off those leads — silently, and
 * with no way back. Two things stand in the way and both are tested here: the
 * controller refuses outright while any lead exists, and Project soft deletes
 * so the cascade cannot fire even for the case that is allowed.
 *
 * THE ARITHMETIC has to agree with the dashboard and the reports, or all three
 * become useless. Bookings and site visits are events read from
 * `todos.outcome_stage` with `completed_at`, distinct by lead; the stage
 * breakdown is where leads stand right now. Those are different numbers on
 * purpose and the tests say so.
 *
 * @see ProjectController
 */
class ProjectsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tele;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-08 11:00', 'Asia/Kolkata'));

        $this->admin = $this->user('admin', 'Ann');
        $this->tele = $this->user('telecaller', 'Tara');
        $this->sales = $this->user('salesperson', 'Sam');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ================= the door ================= */

    public function test_every_route_is_admin_only_by_middleware_not_by_a_hidden_link(): void
    {
        $project = $this->project();

        foreach ([$this->tele, $this->sales] as $user) {
            $this->actingAs($user)->get(route('projects.index'))->assertForbidden();
            $this->actingAs($user)->get(route('projects.show', $project))->assertForbidden();
            $this->actingAs($user)->post(route('projects.store'), $this->payload())->assertForbidden();
            $this->actingAs($user)->put(route('projects.update', $project), $this->payload())->assertForbidden();
            $this->actingAs($user)->delete(route('projects.destroy', $project))->assertForbidden();
            $this->actingAs($user)
                ->put(route('projects.salespeople.update', $project), ['salesperson_ids' => [$this->sales->id]])
                ->assertForbidden();
        }

        $this->assertSame(1, Project::count(), 'nothing was created by a non-admin');
        $this->assertSame(0, $project->salespeople()->count(), 'nor anybody ticked');

        $this->actingAs($this->admin)->get(route('projects.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('projects.show', $project))->assertOk();
    }

    public function test_a_deactivated_admin_is_refused_like_anybody_else(): void
    {
        $this->admin->update(['is_active' => false]);

        $this->actingAs($this->admin)->get(route('projects.index'))->assertForbidden();
    }

    /* ================= the list ================= */

    public function test_the_list_counts_leads_and_bookings_per_project(): void
    {
        $alpha = $this->project(['name' => 'Alpha Heights']);
        $beta = $this->project(['name' => 'Beta Court']);

        // three leads on Alpha, one of which has booked
        $booked = $this->lead($alpha, ['mobile_number' => '9800000001']);
        $this->lead($alpha, ['mobile_number' => '9800000002']);
        $this->lead($alpha, ['mobile_number' => '9800000003']);
        $this->event($booked, 'booking_done');

        // one lead on Beta, no bookings
        $this->lead($beta, ['mobile_number' => '9800000004']);

        $this->actingAs($this->admin)->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Projects/Index')
                ->has('projects.data', 2)
                ->where('projects.data.0.name', 'Alpha Heights')
                ->where('projects.data.0.leads_count', 3)
                ->where('projects.data.0.bookings_count', 1)
                ->where('projects.data.0.deletable', false)
                ->where('projects.data.1.name', 'Beta Court')
                ->where('projects.data.1.leads_count', 1)
                ->where('projects.data.1.bookings_count', 0));
    }

    public function test_a_lead_that_booked_twice_is_counted_once(): void
    {
        $project = $this->project();
        $lead = $this->lead($project);

        // booked, cancelled, booked again — still one lead that got there
        $this->event($lead, 'booking_done', Carbon::parse('2026-08-01 10:00'));
        $this->event($lead, 'booking_done', Carbon::parse('2026-09-01 10:00'));

        $this->actingAs($this->admin)->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('projects.data.0.bookings_count', 1));
    }

    public function test_an_uncompleted_booking_todo_is_not_a_booking(): void
    {
        $project = $this->project();
        $lead = $this->lead($project);

        // an outcome recorded on a row that was never completed is not a thing
        // that happened
        Todo::create([
            'lead_id' => $lead->id, 'assigned_to' => $this->tele->id, 'created_by' => $this->admin->id,
            'scheduled_at' => now(), 'type' => 'call', 'status' => 'pending',
            'outcome_stage' => 'booking_done', 'completed_at' => null,
        ]);

        $this->actingAs($this->admin)->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('projects.data.0.bookings_count', 0));
    }

    public function test_the_list_searches_by_name_and_filters_by_status(): void
    {
        $this->project(['name' => 'Skyline Residency']);
        $this->project(['name' => 'Green Court']);
        $this->project(['name' => 'Old Wing', 'is_active' => false]);

        $this->actingAs($this->admin)->get(route('projects.index', ['reset' => 1, 'search' => 'sky']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('projects.data', 1)
                ->where('projects.data.0.name', 'Skyline Residency'));

        $this->actingAs($this->admin)->get(route('projects.index', ['reset' => 1, 'status' => 'inactive']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('projects.data', 1)
                ->where('projects.data.0.name', 'Old Wing'));

        $this->actingAs($this->admin)->get(route('projects.index', ['reset' => 1, 'status' => 'active']))
            ->assertInertia(fn (Assert $page) => $page->has('projects.data', 2));
    }

    /* ================= the detail ================= */

    public function test_the_detail_reports_stages_sources_events_and_conversion(): void
    {
        $project = $this->project();

        /*
         | Four leads. One booked (and so sits at booking_done now), one
         | visited the site and was then lost, one is in discussion, one is
         | fresh. That second lead is the whole point: it is a site visit that
         | HAPPENED and a lead that stands at Lost, and both have to be true.
         */
        $booked = $this->lead($project, ['mobile_number' => '9800000001', 'source' => 'facebook', 'stage' => 'booking_done']);
        $this->event($booked, 'site_visit_done');
        $this->event($booked, 'booking_done');

        $lost = $this->lead($project, ['mobile_number' => '9800000002', 'source' => 'facebook', 'stage' => 'lost']);
        $this->event($lost, 'site_visit_done');
        $this->event($lost, 'lost');

        $this->lead($project, ['mobile_number' => '9800000003', 'source' => 'walk_in', 'stage' => 'in_discussion']);
        $this->lead($project, ['mobile_number' => '9800000004', 'source' => 'walk_in', 'stage' => 'fresh']);

        $this->actingAs($this->admin)->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Projects/Show')
                ->where('totals.leads', 4)
                // events, from the follow-up history — not leads.stage
                ->where('totals.visits', 2)
                ->where('totals.booked', 1)
                ->where('totals.lost', 1)
                /*
                 | 1 of 4. Asserted as an int because that is what the browser
                 | actually receives — json_encode drops the .0 off a whole
                 | float, and this assertion reads the serialised props.
                 | The raw value is checked below, where it is still a float.
                 */
                ->where('totals.conversion', 25)
                ->has('byStage', count(config('crm.stages')))
                ->has('bySource', 2));

        $response = $this->actingAs($this->admin)->get(route('projects.show', $project));
        $props = $response->viewData('page')['props'];

        // by stage: where leads stand today, and it sums to the lead total
        $stages = collect($props['byStage'])->pluck('total', 'key');
        $this->assertSame(1, $stages['booking_done']);
        $this->assertSame(1, $stages['lost']);
        $this->assertSame(1, $stages['in_discussion']);
        $this->assertSame(1, $stages['fresh']);
        $this->assertSame(4, collect($props['byStage'])->sum('total'), 'the stage breakdown is a partition');
        $this->assertSame(25.0, $props['totals']['conversion'], 'a rounded float before serialisation');

        // by source: sorted by size, empty sources dropped
        $this->assertSame(['facebook', 'walk_in'], collect($props['bySource'])->pluck('key')->all());
        $this->assertSame(4, collect($props['bySource'])->sum('total'));
        $this->assertSame(50.0, $props['bySource'][0]['share']);
    }

    public function test_a_project_with_no_leads_reports_a_null_conversion_not_zero(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin)->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals.leads', 0)
                ->where('totals.booked', 0)
                // null, which the page draws as an em dash. Never 0%.
                ->where('totals.conversion', null));

        $props = $this->actingAs($this->admin)->get(route('projects.show', $project))
            ->viewData('page')['props'];

        foreach ($props['byStage'] as $row) {
            $this->assertNull($row['share'], 'a share of nothing is not 0%');
        }
    }

    public function test_the_detail_lists_recent_leads_and_caps_them(): void
    {
        $project = $this->project();

        for ($i = 0; $i < 13; $i++) {
            $this->lead($project, [
                'mobile_number' => '98000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'first_name' => "Lead$i",
            ]);
        }

        $props = $this->actingAs($this->admin)->get(route('projects.show', $project))
            ->viewData('page')['props'];

        $this->assertCount(10, $props['leads'], 'a preview, not a second Leads page');
        $this->assertSame(13, $props['totals']['leads'], 'the total is still the truth');

        /*
         | The exact order, not just the first row. Every one of these leads was
         | created at the same frozen timestamp, so `created_at` ties on all
         | thirteen and only the `id` tiebreaker decides — without it MySQL and
         | SQLite return different rows here, and the panel can miss the newest
         | lead entirely once the LIMIT is applied.
         */
        $this->assertSame(
            ['Lead12', 'Lead11', 'Lead10', 'Lead9', 'Lead8', 'Lead7', 'Lead6', 'Lead5', 'Lead4', 'Lead3'],
            collect($props['leads'])->map(fn ($l) => explode(' ', $l['name'])[0])->all(),
            'newest first, and stable when created_at ties',
        );
    }

    /* ================= writing ================= */

    public function test_a_project_is_added_and_stamped_with_its_author(): void
    {
        $this->actingAs($this->admin)
            ->post(route('projects.store'), $this->payload([
                'name' => 'Skyline Residency', 'location' => 'Vesu, Surat',
                'type' => 'commercial', 'description' => '2 and 3 BHK. Possession Dec 2027.',
            ]))
            ->assertSessionHasNoErrors();

        $project = Project::firstOrFail();

        $this->assertSame('Skyline Residency', $project->name);
        $this->assertSame('commercial', $project->type);
        $this->assertSame('2 and 3 BHK. Possession Dec 2027.', $project->description);
        $this->assertSame($this->admin->id, $project->created_by);
        $this->assertTrue($project->is_active);
    }

    public function test_the_name_is_unique_but_an_edit_does_not_clash_with_itself(): void
    {
        $this->project(['name' => 'Skyline Residency']);
        $other = $this->project(['name' => 'Green Court']);

        $this->actingAs($this->admin)
            ->post(route('projects.store'), $this->payload(['name' => 'Skyline Residency']))
            ->assertSessionHasErrors('name');

        // renaming a project to something else is fine
        $this->actingAs($this->admin)
            ->put(route('projects.update', $other), $this->payload(['name' => 'Green Court']))
            ->assertSessionHasNoErrors();

        // and taking a name that is already in use is not
        $this->actingAs($this->admin)
            ->put(route('projects.update', $other), $this->payload(['name' => 'Skyline Residency']))
            ->assertSessionHasErrors('name');
    }

    public function test_a_deleted_projects_name_is_free_again(): void
    {
        $project = $this->project(['name' => 'Skyline Residancy']);   // a typo

        $this->actingAs($this->admin)->delete(route('projects.destroy', $project))
            ->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->post(route('projects.store'), $this->payload(['name' => 'Skyline Residancy']))
            ->assertSessionHasNoErrors();
    }

    public function test_an_edit_cannot_reassign_authorship(): void
    {
        $project = $this->project(['created_by' => $this->admin->id]);
        $other = $this->user('admin', 'Bea');

        $this->actingAs($other)
            ->put(route('projects.update', $project), $this->payload(['created_by' => $other->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->admin->id, $project->fresh()->created_by);
    }

    public function test_a_type_outside_the_config_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('projects.store'), $this->payload(['type' => 'industrial']))
            ->assertSessionHasErrors('type');

        $this->assertSame(0, Project::count());
    }

    /* ================= deactivating ================= */

    public function test_deactivating_removes_it_from_the_add_lead_form_and_keeps_everything(): void
    {
        $project = $this->project();
        $lead = $this->lead($project);

        $this->actingAs($this->admin)
            ->put(route('projects.update', $project), $this->payload([
                'name' => $project->name, 'is_active' => false,
            ]))
            ->assertSessionHas('warning', fn (string $m) => str_contains($m, 'Add lead form'));

        $this->assertFalse($project->fresh()->is_active);

        // gone from the picker the Add lead form renders
        $this->assertSame(0, Project::active()->count());

        // and nothing else moved
        $this->assertSame($project->id, $lead->fresh()->project_id);
        $this->assertNotNull($lead->fresh()->project, 'history still resolves');
        $this->assertSame(1, Lead::count());
    }

    /* ================= deleting ================= */

    public function test_a_project_with_leads_cannot_be_deleted_and_says_why(): void
    {
        $project = $this->project();
        $lead = $this->lead($project);
        $todo = $this->event($lead, 'site_visit_done');

        $this->actingAs($this->admin)->delete(route('projects.destroy', $project))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'Switch the project off instead'));

        // the whole point: nothing was cascaded away
        $this->assertNotNull($project->fresh(), 'the project survives');
        $this->assertNull($project->fresh()->deleted_at);
        $this->assertSame(1, Lead::count());
        $this->assertNotNull($lead->fresh());
        $this->assertNotNull($todo->fresh());
    }

    public function test_a_project_whose_leads_are_only_in_the_bin_still_cannot_be_deleted(): void
    {
        $project = $this->project();
        $lead = $this->lead($project);

        $lead->delete();   // soft deleted, and restorable

        $this->assertSame(0, Lead::where('project_id', $project->id)->count(), 'invisible to a normal query');

        $this->actingAs($this->admin)->delete(route('projects.destroy', $project))
            ->assertSessionHas('error');

        $this->assertNull($project->fresh()->deleted_at,
            'a lead in the bin can be restored and would need its project back');
    }

    /**
     * The button's state and the server's answer are the same test.
     *
     * `leads_count` on the list excludes trashed leads, so a project whose
     * leads had all been deleted would show 0 and — on an earlier version of
     * this — offer a Delete button the server then refused. `deletable` is
     * computed on the count destroy() actually applies.
     */
    public function test_the_delete_button_is_withheld_for_leads_that_are_only_in_the_bin(): void
    {
        $project = $this->project();
        $lead = $this->lead($project);

        $lead->delete();

        $this->actingAs($this->admin)->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                // the visible count is live leads, which is what an admin cares about
                ->where('projects.data.0.leads_count', 0)
                // but the button is withheld, matching what destroy() would do
                ->where('projects.data.0.deletable', false));
    }

    public function test_a_project_with_no_leads_is_soft_deleted_and_the_cascade_never_fires(): void
    {
        $keep = $this->project(['name' => 'Keep']);
        $keepers = $this->lead($keep);
        $empty = $this->project(['name' => 'Empty']);

        $this->actingAs($this->admin)->delete(route('projects.destroy', $empty))
            ->assertSessionHas('success');

        // the row is still there, which is what stops leads.project_id cascading
        $this->assertNotNull(Project::withTrashed()->find($empty->id));
        $this->assertNotNull(Project::withTrashed()->find($empty->id)->deleted_at);
        $this->assertNull(Project::find($empty->id), 'and it is gone from every normal query');

        // the other project and its lead are untouched
        $this->assertNotNull($keepers->fresh());
        $this->assertSame(1, Lead::count());
    }

    public function test_a_deleted_project_leaves_the_add_lead_picker_and_the_list(): void
    {
        $project = $this->project();

        $this->actingAs($this->admin)->delete(route('projects.destroy', $project));

        $this->assertSame(0, Project::active()->count());

        $this->actingAs($this->admin)->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->has('projects.data', 0));
    }

    /* ================= salespeople ================= */

    public function test_the_detail_lists_every_active_salesperson_and_who_is_ticked(): void
    {
        $project = $this->project();
        $nia = $this->user('salesperson', 'Nia');
        $off = $this->user('salesperson', 'Off');
        $off->update(['is_active' => false]);
        $project->salespeople()->attach([$nia->id, $off->id]);

        $this->actingAs($this->admin)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                // active salespeople only — not the telecaller, not Off
                ->has('salespeople', 2)
                ->where('salespeople.0', ['id' => $nia->id, 'name' => 'Nia User', 'assigned' => true])
                ->where('salespeople.1', ['id' => $this->sales->id, 'name' => 'Sam User', 'assigned' => false]));
    }

    /**
     * Saving removes only the people the page showed unticked: a salesperson
     * switched off keeps their projects for when they come back. Ticking
     * somebody answers the "no salesperson" alert on every admin's bell.
     */
    public function test_saving_the_ticks_keeps_switched_off_salespeople_and_clears_the_alert(): void
    {
        $project = $this->project();
        $nia = $this->user('salesperson', 'Nia');
        $off = $this->user('salesperson', 'Off');
        $off->update(['is_active' => false]);
        $project->salespeople()->attach([$nia->id, $off->id]);

        app(AlertService::class)->raise($this->admin, "project_without_salespeople.{$project->id}", 'No salesperson');

        $this->actingAs($this->admin)
            ->put(route('projects.salespeople.update', $project), ['salesperson_ids' => [$this->sales->id]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertEqualsCanonicalizing([$this->sales->id, $off->id], $project->salespeople()->pluck('users.id')->all());
        $this->assertSame(0, Alert::unread()->count(), 'answered');

        // unticking everybody is allowed, and says what it means
        $this->actingAs($this->admin)
            ->put(route('projects.salespeople.update', $project), ['salesperson_ids' => []])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning');

        $this->assertSame([$off->id], $project->salespeople()->pluck('users.id')->all());
    }

    public function test_only_an_active_salesperson_can_be_ticked(): void
    {
        $project = $this->project();
        $off = $this->user('salesperson', 'Off');
        $off->update(['is_active' => false]);

        foreach ([$this->tele->id, $this->admin->id, $off->id, 999999] as $id) {
            $this->actingAs($this->admin)
                ->put(route('projects.salespeople.update', $project), ['salesperson_ids' => [$this->sales->id, $id]])
                ->assertSessionHasErrors('salesperson_ids.1');
        }

        $this->assertSame(0, $project->salespeople()->count(), 'nothing half-saved');
    }

    /* ================= helpers ================= */

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'A project',
            'location' => 'Surat',
            'type' => 'residential',
            'is_active' => true,
        ];
    }

    private function project(array $attrs = []): Project
    {
        return Project::create($attrs + [
            'name' => 'Skyline Residency',
            'location' => 'Vesu, Surat',
            'type' => 'residential',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    private function lead(Project $project, array $attrs = []): Lead
    {
        return Lead::create($attrs + [
            'first_name' => 'Rahul',
            'last_name' => 'Mehta',
            'mobile_number' => '9876543210',
            'project_id' => $project->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            'assigned_to' => $this->tele->id,
            'assigned_role' => 'telecaller',
            'stage_changed_at' => now(),
            'created_by' => $this->admin->id,
        ]);
    }

    /** A completed to-do recording that the lead reached a stage. */
    private function event(Lead $lead, string $stage, ?Carbon $at = null): Todo
    {
        return Todo::create([
            'lead_id' => $lead->id,
            'assigned_to' => $lead->assigned_to,
            'created_by' => $this->admin->id,
            'scheduled_at' => $at ?? now(),
            'type' => 'call',
            'status' => 'completed',
            'outcome_stage' => $stage,
            'completed_at' => $at ?? now(),
            'completed_by' => $this->admin->id,
        ]);
    }

    private function user(string $role, string $first): User
    {
        return User::create([
            'first_name' => $first,
            'last_name' => 'User',
            'email' => strtolower($first).'@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role,
            'is_active' => true,
            'password' => 'password',
        ]);
    }
}
