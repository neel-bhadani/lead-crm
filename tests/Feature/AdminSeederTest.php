<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\ChannelPartner;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `db:seed` on a production install: one admin who can sign in, and nothing
 * that looks like somebody's data.
 *
 * @see AdminSeeder
 * @see DatabaseSeeder
 */
class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_makes_one_approved_admin_and_no_demo_data(): void
    {
        $this->seed();

        $admin = User::sole();

        $this->assertSame('Admin', $admin->first_name);
        $this->assertSame('One', $admin->last_name);
        $this->assertSame('admin1@gmail.com', $admin->email);
        $this->assertSame('9512779295', $admin->mobile_number);
        $this->assertSame('admin', $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertSame('approved', $admin->approval_status, 'a pending admin has nobody to approve them');
        $this->assertTrue(Hash::check('123456789', $admin->password), 'hashed once, by the cast');

        foreach ([Lead::class, Todo::class, Project::class, ChannelPartner::class, AutomationRule::class, MessageTemplate::class] as $model) {
            $this->assertSame(0, $model::count(), "$model was seeded");
        }

        // the reference data the app boots on comes from its migration, not the seeder
        $this->assertGreaterThan(0, DB::table('lead_stages')->count());
        $this->assertGreaterThan(0, DB::table('lead_sources')->count());
    }

    public function test_the_seeded_admin_can_sign_in_and_reach_the_dashboard(): void
    {
        $this->seed();

        $this->post('/login', ['login' => 'admin1@gmail.com', 'password' => '123456789'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs(User::sole());

        $this->get('/dashboard')->assertOk();
    }

    public function test_reseeding_neither_duplicates_the_admin_nor_resets_their_password(): void
    {
        $this->seed();
        User::sole()->update(['password' => 'changed-since']);

        $this->seed();

        $this->assertTrue(Hash::check('changed-since', User::sole()->password));
    }
}
