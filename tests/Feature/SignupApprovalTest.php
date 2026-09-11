<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Sign-up, and the admin approval that stands between it and a working account.
 *
 * Four things are being protected, and each has its own section below:
 *
 *   the role        `role=admin` is refused by the server, posted directly.
 *   the door        a pending or rejected account cannot sign in — and is told
 *                   why, but only once its password checks out.
 *   the admins      somebody is told a request is waiting.
 *   the rate        sign-up is throttled like sign-in, failures included.
 *
 * @see \App\Http\Controllers\Auth\SignupController
 * @see \App\Http\Requests\Auth\SignupRequest
 * @see \App\Http\Requests\Auth\LoginRequest
 */
class SignupApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-11 10:30', 'Asia/Kolkata'));

        $this->admin = User::factory()->role('admin')->create(['first_name' => 'Ann']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ---------------- the page ---------------- */

    public function test_the_sign_in_page_opens_on_sign_in_and_offers_only_staff_roles(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->where('tab', 'signin')
            ->where('roles', [
                ['value' => 'telecaller', 'label' => 'Telecaller'],
                ['value' => 'salesperson', 'label' => 'Salesperson'],
            ]));
    }

    public function test_signup_is_the_same_page_on_its_other_tab(): void
    {
        $this->get('/signup')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->where('tab', 'signup'));
    }

    /** It listed three real addresses and the shared password. It must not ship. */
    public function test_the_demo_account_hint_is_gone_from_the_sign_in_page(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Auth/Login.vue'));

        foreach (['admin@crm.test', 'tele@crm.test', 'sales@crm.test', 'Demo accounts'] as $needle) {
            $this->assertStringNotContainsString($needle, $source);
        }
    }

    public function test_the_old_breeze_doors_stay_shut(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', $this->payload())->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'priya@example.test']);
    }

    /* ---------------- signing up ---------------- */

    public function test_signing_up_writes_a_pending_switched_off_account_and_logs_nobody_in(): void
    {
        $this->post('/signup', $this->payload())
            ->assertRedirect(route('signup.submitted'))
            ->assertSessionHasNoErrors();

        $this->assertGuest();

        $user = User::where('email', 'priya@example.test')->firstOrFail();

        $this->assertSame('pending', $user->approval_status);
        $this->assertFalse($user->is_active);
        $this->assertNull($user->permissions);
        $this->assertSame('salesperson', $user->role);
        $this->assertSame('Priya', $user->first_name);
        $this->assertSame('9876500001', $user->mobile_number);
        // hashed once, by the cast
        $this->assertTrue(Hash::check('secret-pass', $user->password));
    }

    public function test_the_confirmation_screen_says_what_happens_next(): void
    {
        $this->followingRedirects()
            ->post('/signup', $this->payload())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/SignupSubmitted')
                ->where('account.name', 'Priya')
                ->where('account.email', 'priya@example.test'));

        // readable on its own after the flash has gone
        $this->get('/signup/submitted')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Auth/SignupSubmitted')
            ->where('account', null));
    }

    public function test_a_signed_in_user_is_sent_away_from_the_signup_routes(): void
    {
        $this->actingAs($this->admin)->get('/signup')->assertRedirect();
        $this->actingAs($this->admin)->post('/signup', $this->payload())->assertRedirect();

        $this->assertDatabaseMissing('users', ['email' => 'priya@example.test']);
    }

    /* ---------------- the role ---------------- */

    public function test_role_admin_posted_directly_is_refused(): void
    {
        $this->from('/signup')
            ->post('/signup', $this->payload(['role' => 'admin']))
            ->assertRedirect('/signup')
            ->assertSessionHasErrors(['role' => 'Choose telecaller or salesperson.']);

        $this->assertDatabaseMissing('users', ['email' => 'priya@example.test']);
        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    public function test_any_role_outside_the_staff_list_is_refused(): void
    {
        foreach (['manager', 'ADMIN', '', 'superadmin'] as $role) {
            $this->post('/signup', $this->payload(['role' => $role]))->assertSessionHasErrors('role');
        }

        $this->assertDatabaseMissing('users', ['email' => 'priya@example.test']);
    }

    /** Fields the form does not have cannot be smuggled in beside the ones it does. */
    public function test_a_signup_cannot_arrive_switched_on_approved_or_holding_permissions(): void
    {
        $this->post('/signup', $this->payload([
            'is_active'       => 1,
            'approval_status' => 'approved',
            'permissions'     => ['see_all_leads' => true, 'delete_leads' => true],
        ]))->assertSessionHasNoErrors();

        $user = User::where('email', 'priya@example.test')->firstOrFail();

        $this->assertSame('pending', $user->approval_status);
        $this->assertFalse($user->is_active);
        $this->assertNull($user->permissions);
    }

    /* ---------------- uniqueness ---------------- */

    public function test_email_and_mobile_must_be_unused_including_by_deleted_users(): void
    {
        $gone = User::factory()->create(['email' => 'gone@example.test', 'mobile_number' => '9876500999']);
        $gone->delete();

        $this->post('/signup', $this->payload(['email' => 'gone@example.test']))
            ->assertSessionHasErrors(['email' => 'That email is already registered.']);

        $this->post('/signup', $this->payload(['mobile_number' => '9876500999']))
            ->assertSessionHasErrors(['mobile_number' => 'That mobile number is already registered.']);

        $this->post('/signup', $this->payload(['email' => $this->admin->email]))
            ->assertSessionHasErrors(['email' => 'That email is already registered.']);

        $this->assertSame(2, User::withTrashed()->count());
    }

    public function test_the_ordinary_field_rules_apply(): void
    {
        $this->post('/signup', $this->payload([
            'first_name' => '', 'email' => 'not-an-email', 'mobile_number' => '12345',
            'password' => 'short', 'password_confirmation' => 'short',
        ]))->assertSessionHasErrors(['first_name', 'email', 'mobile_number', 'password']);

        $this->post('/signup', $this->payload(['password_confirmation' => 'different-pass']))
            ->assertSessionHasErrors(['password' => 'The two passwords do not match.']);
    }

    /* ---------------- the door ---------------- */

    public function test_a_pending_user_cannot_sign_in_and_is_told_they_are_waiting(): void
    {
        $this->post('/signup', $this->payload());

        $this->from('/login')->post('/login', ['login' => 'priya@example.test', 'password' => 'secret-pass'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors([
                'approval' => 'Your account is waiting for approval from an administrator.',
            ]);

        $this->assertGuest();

        // by mobile number too
        $this->post('/login', ['login' => '9876500001', 'password' => 'secret-pass'])
            ->assertSessionHasErrors('approval');

        $this->assertGuest();
    }

    /** Without the password it is an ordinary failure — no asking "is this address waiting". */
    public function test_a_wrong_password_on_a_pending_account_gets_the_generic_message(): void
    {
        User::factory()->pending()->create(['email' => 'wait@example.test']);

        $this->post('/login', ['login' => 'wait@example.test', 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['login' => trans('auth.failed')])
            ->assertSessionDoesntHaveErrors('approval');

        $this->assertGuest();
    }

    public function test_a_rejected_user_cannot_sign_in_and_is_told_so(): void
    {
        User::factory()->rejected()->create(['email' => 'no@example.test']);

        $this->post('/login', ['login' => 'no@example.test', 'password' => 'password'])
            ->assertSessionHasErrors([
                'approval' => 'Your account request was not approved. Contact your administrator.',
            ]);

        $this->assertGuest();
    }

    /** A person who used to work here keeps the no-enumeration message. */
    public function test_a_deactivated_approved_user_still_gets_the_generic_message(): void
    {
        User::factory()->inactive()->create(['email' => 'left@example.test']);

        $this->post('/login', ['login' => 'left@example.test', 'password' => 'password'])
            ->assertSessionHasErrors(['login' => trans('auth.failed')])
            ->assertSessionDoesntHaveErrors('approval');
    }

    /** is_active flipped behind the Users page's back is still not a way in. */
    public function test_a_pending_account_switched_on_in_the_database_still_cannot_sign_in(): void
    {
        $user = User::factory()->pending()->create(['email' => 'sneak@example.test']);
        $user->forceFill(['is_active' => true])->save();

        $this->post('/login', ['login' => 'sneak@example.test', 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_the_login_rate_limit_still_counts_approval_refusals(): void
    {
        User::factory()->pending()->create(['email' => 'wait@example.test']);

        foreach (range(1, 5) as $i) {
            $this->post('/login', ['login' => 'wait@example.test', 'password' => 'password'])
                ->assertSessionHasErrors('approval');
        }

        $this->post('/login', ['login' => 'wait@example.test', 'password' => 'password'])
            ->assertSessionHasErrors('login')
            ->assertSessionDoesntHaveErrors('approval');
    }

    /* ---------------- the admins ---------------- */

    public function test_every_active_admin_is_alerted_and_nobody_else(): void
    {
        $second   = User::factory()->role('admin')->create();
        $offAdmin = User::factory()->role('admin')->inactive()->create();
        $sales    = User::factory()->role('salesperson')->create();

        $this->post('/signup', $this->payload(['role' => 'telecaller']));

        $user = User::where('email', 'priya@example.test')->firstOrFail();

        foreach ([$this->admin, $second] as $admin) {
            $alert = Alert::where('user_id', $admin->id)->sole();

            $this->assertSame("account_pending.{$user->id}", $alert->type);
            $this->assertSame('Priya Shah is waiting for approval', $alert->title);
            $this->assertStringContainsString('telecaller', $alert->body);
            $this->assertStringContainsString('priya@example.test', $alert->body);
            $this->assertNull($alert->read_at);
            $this->assertNull($alert->lead_id);
            $this->assertSame(route('users.index', ['reset' => 1, 'status' => 'pending']), $alert->action_url);
        }

        $this->assertSame(0, Alert::where('user_id', $offAdmin->id)->count());
        $this->assertSame(0, Alert::where('user_id', $sales->id)->count());
        $this->assertSame(0, Alert::where('user_id', $user->id)->count());
    }

    /** Keyed per account, so the 24-hour dedupe cannot swallow the second request. */
    public function test_two_signups_on_one_day_are_two_alerts(): void
    {
        $this->post('/signup', $this->payload());
        $this->post('/signup', $this->payload(['email' => 'ravi@example.test', 'mobile_number' => '9876500002']));

        $this->assertSame(2, Alert::where('user_id', $this->admin->id)->unread()->count());
    }

    public function test_the_alert_shows_in_the_admins_bell(): void
    {
        $this->post('/signup', $this->payload());

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('alerts.unread', 1)
            ->where('alerts.recent.0.title', 'Priya Shah is waiting for approval'));
    }

    /* ---------------- approving and rejecting ---------------- */

    public function test_approving_switches_the_account_on_with_role_defaults_and_they_can_sign_in(): void
    {
        $user = User::factory()->pending()->role('telecaller')->create([
            'email' => 'tara@example.test',
            // whatever was on the row, approval starts them on the role's defaults
            'permissions' => ['see_all_leads' => true],
        ]);

        $this->actingAs($this->admin)
            ->post("/users/{$user->id}/approve")
            ->assertRedirect()
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('approved', $user->approval_status);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->permissions);
        $this->assertFalse($user->can_('see_all_leads'));
        $this->assertFalse($user->can_('add_leads'));   // telecaller default

        $this->post('/logout');
        $this->post('/login', ['login' => 'tara@example.test', 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_answering_a_request_clears_it_from_every_admins_bell(): void
    {
        $second = User::factory()->role('admin')->create();

        $this->post('/signup', $this->payload());
        $user = User::where('email', 'priya@example.test')->firstOrFail();

        $this->assertSame(2, Alert::where('type', "account_pending.{$user->id}")->unread()->count());

        $this->actingAs($this->admin)->post("/users/{$user->id}/approve");

        $this->assertSame(0, Alert::where('type', "account_pending.{$user->id}")->unread()->count());
        // read, not deleted: the Alerts page still shows it was raised
        $this->assertSame(1, Alert::where('user_id', $second->id)->count());
    }

    public function test_rejecting_leaves_the_account_off_and_marked(): void
    {
        $user = User::factory()->pending()->create();

        $this->actingAs($this->admin)->post("/users/{$user->id}/reject")->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('rejected', $user->approval_status);
        $this->assertFalse($user->is_active);
    }

    public function test_a_rejected_request_can_be_approved_later(): void
    {
        $user = User::factory()->rejected()->create();

        $this->actingAs($this->admin)->post("/users/{$user->id}/approve");

        $this->assertSame('approved', $user->fresh()->approval_status);
        $this->assertTrue($user->fresh()->is_active);
    }

    /** Stopping a working account is deactivation, which hands their work over first. */
    public function test_an_approved_account_cannot_be_rejected_or_re_approved(): void
    {
        $working = User::factory()->role('salesperson')->create(['permissions' => ['see_all_leads' => true]]);

        $this->actingAs($this->admin)->post("/users/{$working->id}/reject")->assertSessionHas('error');
        $this->actingAs($this->admin)->post("/users/{$working->id}/approve")->assertSessionHas('error');

        $working->refresh();
        $this->assertSame('approved', $working->approval_status);
        $this->assertTrue($working->is_active);
        // re-approving must not have reset a deliberate grant
        $this->assertSame(['see_all_leads' => true], $working->permissions);
    }

    public function test_only_an_admin_can_answer_a_request(): void
    {
        $user = User::factory()->pending()->create();

        foreach (['telecaller', 'salesperson'] as $role) {
            $staff = User::factory()->role($role)->create(['permissions' => ['see_all_leads' => true]]);

            $this->actingAs($staff)->post("/users/{$user->id}/approve")->assertForbidden();
            $this->actingAs($staff)->post("/users/{$user->id}/reject")->assertForbidden();
        }

        $this->assertSame('pending', $user->fresh()->approval_status);
    }

    /** The Edit modal's Active tick is not a second way to approve somebody. */
    public function test_a_pending_account_cannot_be_switched_on_from_the_edit_modal(): void
    {
        $user = User::factory()->pending()->role('telecaller')->create();

        $this->actingAs($this->admin)->put("/users/{$user->id}", [
            'first_name' => $user->first_name, 'last_name' => $user->last_name,
            'email' => $user->email, 'mobile_number' => $user->mobile_number,
            'role' => 'telecaller', 'is_active' => true,
        ])->assertSessionHasErrors('is_active');

        $this->assertFalse($user->fresh()->is_active);

        // editing the rest of the row still works while they wait
        $this->actingAs($this->admin)->put("/users/{$user->id}", [
            'first_name' => 'Fixed', 'last_name' => $user->last_name,
            'email' => $user->email, 'mobile_number' => $user->mobile_number,
            'role' => 'salesperson', 'is_active' => false,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Fixed', $user->fresh()->first_name);
        $this->assertSame('pending', $user->fresh()->approval_status);
    }

    /* ---------------- the Users page ---------------- */

    public function test_the_users_page_filters_by_pending_and_lists_them_first(): void
    {
        $pending  = User::factory()->pending()->create(['first_name' => 'Zed']);
        $rejected = User::factory()->rejected()->create();
        $off      = User::factory()->inactive()->create();

        $this->actingAs($this->admin)->get('/users?reset=1')->assertInertia(fn (Assert $page) => $page
            ->where('users.data.0.id', $pending->id)
            ->where('users.data.0.approval_status', 'pending')
            ->where('users.data.0.signed_up_on', '11 Sep 2026')
            ->where('options.pendingCount', 1));

        $ids = fn (string $status) => collect(
            $this->actingAs($this->admin)->get("/users?reset=1&status={$status}")
                ->viewData('page')['props']['users']['data']
        )->pluck('id')->all();

        $this->assertSame([$pending->id], $ids('pending'));
        $this->assertSame([$rejected->id], $ids('rejected'));
        // switched off after working here — not a sign-up nobody has looked at
        $this->assertSame([$off->id], $ids('inactive'));
        $this->assertSame([$this->admin->id], $ids('active'));
    }

    public function test_never_approved_accounts_stay_out_of_the_staff_filters(): void
    {
        User::factory()->pending()->role('telecaller')->create(['first_name' => 'Waiting']);
        User::factory()->inactive()->role('telecaller')->create(['first_name' => 'Former']);

        $names = collect(
            $this->actingAs($this->admin)->get('/leads')->viewData('page')['props']['options']['users']
        )->pluck('first_name')->all();

        $this->assertContains('Former', $names);
        $this->assertNotContains('Waiting', $names);
    }

    /* ---------------- rate limiting ---------------- */

    /** Failures count: they are the attempts a script working through a list makes. */
    public function test_signup_attempts_are_rate_limited_pass_or_fail(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post('/signup', $this->payload(['role' => 'admin']))->assertSessionHasErrors('role');
        }

        $this->post('/signup', $this->payload())
            ->assertSessionHasErrors('signup')
            ->assertSessionDoesntHaveErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'priya@example.test']);

        // the window is a minute
        $this->travel(61)->seconds();

        $this->post('/signup', $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'priya@example.test']);
    }

    public function test_accounts_created_from_one_address_are_capped_per_hour(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post('/signup', $this->payload([
                'email' => "person{$i}@example.test", 'mobile_number' => "987650010{$i}",
            ]))->assertSessionHasNoErrors();

            // step past the per-minute window so only the hourly cap is in play
            $this->travel(61)->seconds();
        }

        $this->post('/signup', $this->payload(['email' => 'sixth@example.test', 'mobile_number' => '9876500199']))
            ->assertSessionHasErrors('signup');

        $this->assertDatabaseMissing('users', ['email' => 'sixth@example.test']);
        $this->assertSame(5, User::where('approval_status', 'pending')->count());
    }

    /* ---------------- the invariant ---------------- */

    public function test_the_open_lead_invariant_survives_the_whole_flow(): void
    {
        $tele    = User::factory()->role('telecaller')->create();
        $project = Project::create(['name' => 'Alpha']);
        $lead    = Lead::create([
            'first_name' => 'Meera', 'last_name' => 'Sharma', 'mobile_number' => '9811100000',
            'project_id' => $project->id, 'source' => 'walk_in', 'stage' => 'connected',
            'assigned_to' => $tele->id, 'assigned_role' => 'telecaller', 'created_by' => $this->admin->id,
        ]);
        Todo::create([
            'lead_id' => $lead->id, 'assigned_to' => $tele->id, 'created_by' => $this->admin->id,
            'scheduled_at' => now()->addDay(), 'type' => 'call', 'status' => 'pending',
        ]);

        $this->post('/signup', $this->payload());
        $user = User::where('email', 'priya@example.test')->firstOrFail();
        $this->actingAs($this->admin)->post("/users/{$user->id}/approve");

        $second = User::factory()->pending()->create();
        $this->actingAs($this->admin)->post("/users/{$second->id}/reject");

        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /* ---------------- fixtures ---------------- */

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name'            => 'Priya',
            'last_name'             => 'Shah',
            'email'                 => 'priya@example.test',
            'mobile_number'         => '9876500001',
            'role'                  => 'salesperson',
            'password'              => 'secret-pass',
            'password_confirmation' => 'secret-pass',
        ], $overrides);
    }
}
