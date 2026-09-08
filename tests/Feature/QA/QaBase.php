<?php

namespace Tests\Feature\QA;

use App\Models\ChannelPartner;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Shared fixtures for the QA probes. Test-only; deleted after the run. */
abstract class QaBase extends TestCase
{
    protected User $admin;
    protected User $tele;
    protected User $sales;
    protected Project $project;
    protected Project $project2;

    protected function boot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-08 11:00', 'Asia/Kolkata'));

        $this->admin = $this->mkUser('admin', 'Ann');
        $this->tele  = $this->mkUser('telecaller', 'Tara');
        $this->sales = $this->mkUser('salesperson', 'Sam');

        $this->project  = Project::create(['name' => 'Skyline Residency', 'location' => 'Vesu', 'type' => 'residential', 'is_active' => true, 'created_by' => $this->admin->id]);
        $this->project2 = Project::create(['name' => 'Green Court', 'location' => 'Pal', 'type' => 'residential', 'is_active' => true, 'created_by' => $this->admin->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function mkUser(string $role, string $first, bool $active = true): User
    {
        return User::create([
            'first_name' => $first, 'last_name' => 'Q',
            'email' => strtolower($first) . '@qa.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role, 'is_active' => $active, 'password' => 'password',
        ]);
    }

    protected function mkLead(array $attrs = []): Lead
    {
        static $n = 0;
        $n++;

        return Lead::create($attrs + [
            'first_name' => 'Rahul', 'last_name' => 'Mehta',
            'mobile_number' => '98765' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'project_id' => $this->project->id, 'source' => 'walk_in', 'stage' => 'fresh',
            'assigned_to' => $this->tele->id, 'assigned_role' => 'telecaller',
            'stage_changed_at' => now(), 'created_by' => $this->admin->id,
        ]);
    }

    protected function mkTodo(Lead $lead, array $attrs = []): Todo
    {
        return Todo::create($attrs + [
            'lead_id' => $lead->id, 'assigned_to' => $lead->assigned_to,
            'created_by' => $this->admin->id, 'scheduled_at' => now()->addDay(),
            'type' => 'call', 'status' => 'pending',
        ]);
    }

    /** A completed to-do recording that a lead reached a stage. */
    protected function mkEvent(Lead $lead, string $stage, ?Carbon $at = null): Todo
    {
        return Todo::create([
            'lead_id' => $lead->id, 'assigned_to' => $lead->assigned_to,
            'created_by' => $this->admin->id, 'scheduled_at' => $at ?? now(),
            'type' => 'call', 'status' => 'completed', 'outcome_stage' => $stage,
            'completed_at' => $at ?? now(), 'completed_by' => $this->admin->id,
        ]);
    }

    protected function leadPayload(array $o = []): array
    {
        return $o + [
            'first_name' => 'New', 'last_name' => 'Lead',
            'mobile_number' => '9812345678', 'project_id' => $this->project->id,
            'source' => 'walk_in', 'stage' => 'fresh',
            'follow_up_at' => '2026-09-12 11:00', 'follow_up_type' => 'call',
        ];
    }

    /** THE invariant. */
    protected function invariant(string $where): void
    {
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count(),
            "INVARIANT BROKEN after: $where");
    }

    /** Leads holding more than one pending follow-up. */
    protected function noDoubleTodos(string $where): void
    {
        $dupes = Lead::withCount(['todos as pending' => fn ($q) => $q->where('status', 'pending')])
            ->get()->where('pending', '>', 1);

        $this->assertCount(0, $dupes, "DOUBLE PENDING TODO after: $where");
    }
}
