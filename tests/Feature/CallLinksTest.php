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
 * CallButtons builds its tel: and wa.me hrefs from the dialling code in
 * config/crm.php, delivered through each page's `options` prop. A missing key
 * would not throw — it would quietly drop the country code out of every link —
 * so both pages that render the component are pinned here.
 */
class CallLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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
    }

    public function test_the_todo_page_carries_the_dialling_code(): void
    {
        $this->actingAs($this->admin)
            ->get('/todos')
            ->assertInertia(fn(Assert $page) => $page->where('options.countryCode', '+91'));
    }

    public function test_the_dashboard_carries_the_dialling_code(): void
    {
        $this->actingAs($this->admin)->withHeaders([
            'X-Inertia'                   => 'true',
            'X-Inertia-Version'           => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Data'      => 'options',
            'X-Inertia-Partial-Component' => 'Dashboard',
        ])->get('/dashboard')->assertJsonPath('props.options.countryCode', '+91');
    }

    /** Changing the config is all it should take — no Vue file names the code. */
    public function test_the_code_follows_the_config(): void
    {
        config(['crm.country_code' => '+44']);

        $this->actingAs($this->admin)
            ->get('/todos')
            ->assertInertia(fn(Assert $page) => $page->where('options.countryCode', '+44'));
    }

    /** The links are plain anchors: opening a page writes nothing. */
    public function test_rendering_the_rows_creates_no_todos_and_moves_no_stages(): void
    {
        $project = Project::create(['name' => 'Alpha']);
        $lead    = Lead::create([
            'first_name'    => 'Meera',
            'last_name'     => 'Sharma',
            'mobile_number' => '9876543210',
            'project_id'    => $project->id,
            'source'        => 'walk_in',
            'stage'         => 'connected',
            'assigned_to'   => $this->admin->id,
            'created_by'    => $this->admin->id,
        ]);
        Todo::create([
            'lead_id'      => $lead->id,
            'assigned_to'  => $this->admin->id,
            'created_by'   => $this->admin->id,
            'scheduled_at' => today()->setTime(10, 0),
            'type'         => 'call',
            'status'       => 'pending',
        ]);

        $before = [Todo::count(), $lead->stage];

        $this->actingAs($this->admin)->get('/todos')->assertOk();

        $this->assertSame($before, [Todo::count(), $lead->fresh()->stage]);
    }
}
