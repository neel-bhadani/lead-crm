<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The legacy import gives thousands of leads to switched-off users, and some
 * to no mobile number at all. Every page an admin opens has to render them —
 * the owner still named, not dropped because the account is inactive.
 */
class InactiveOwnerPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $inactive;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-13 10:00'));

        $this->admin = User::factory()->role('admin')->create();
        $this->inactive = User::factory()->role('salesperson')->inactive()
            ->create(['first_name' => 'Dormant', 'last_name' => 'Owner']);
        $this->project = Project::create(['name' => 'Felicity']);

        $open = $this->lead(['stage' => 'site_visit_done', 'created_at' => '2026-09-01 00:00:00']);
        $this->completedTodo($open, '2026-09-02 00:00:00', 'site_visit_done');

        $lost = $this->lead(['stage' => 'lost', 'reason' => 'not_interested', 'created_at' => '2024-04-02 00:00:00']);
        $this->completedTodo($lost, '2024-04-30 00:00:00', 'lost');

        $this->lead(['stage' => 'not_connected', 'mobile_number' => null, 'created_at' => '2026-08-20 00:00:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_every_admin_page_renders_leads_held_by_an_inactive_user(): void
    {
        foreach ([
            '/dashboard',
            '/todos?reset=1',
            '/pipeline',
            '/channel-partners',
            '/alerts',
            "/projects/{$this->project->id}",
            '/projects',
        ] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }

    public function test_the_owner_is_still_named_where_leads_and_reports_list_owners(): void
    {
        foreach ([
            '/leads?reset=1',
            '/reports/leads?reset=1&group=assigned_to&range=all',
            '/reports/followups?reset=1&group=assigned_to&status=completed&range=all',
            '/users?reset=1',
        ] as $url) {
            $response = $this->actingAs($this->admin)->get($url)->assertOk();

            $this->assertStringContainsString(
                'Dormant Owner',
                json_encode($response->viewData('page')['props']),
                "{$url} lost the inactive owner's name",
            );
        }
    }

    public function test_an_inactive_owner_is_still_offered_in_the_leads_filter(): void
    {
        $props = $this->actingAs($this->admin)->get('/leads?reset=1')
            ->assertOk()->viewData('page')['props'];

        $this->assertContains($this->inactive->id, array_column(data_get($props, 'options.users'), 'id'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lead(array $attributes): Lead
    {
        $createdAt = $attributes['created_at'];
        unset($attributes['created_at']);

        $lead = Lead::create($attributes + [
            'first_name' => 'Legacy',
            'last_name' => 'Customer',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $this->project->id,
            'source' => 'facebook',
            'assigned_to' => $this->inactive->id,
            'assigned_role' => 'salesperson',
        ]);
        $lead->forceFill(['created_at' => $createdAt])->save();

        return $lead;
    }

    private function completedTodo(Lead $lead, string $at, string $outcome): void
    {
        Todo::create([
            'lead_id' => $lead->id,
            'assigned_to' => $this->inactive->id,
            'scheduled_at' => $at,
            'type' => 'call',
            'status' => 'completed',
            'remarks' => 'Imported',
            'outcome_stage' => $outcome,
            'completed_at' => $at,
            'completed_by' => $this->inactive->id,
        ]);
    }
}
