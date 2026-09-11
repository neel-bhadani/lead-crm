<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * My profile, at /account.
 *
 * Five columns belong to the person — first name, last name, email, mobile and
 * password. Everything else on the row is the admin's, and the point of these
 * tests is that posting it anyway changes nothing: a salesperson cannot grant
 * themselves `see_all_leads`, promote themselves, or switch themselves on.
 *
 * And there is no delete, here or at the old /profile.
 *
 * @see \App\Http\Controllers\AccountController
 * @see \App\Http\Requests\ProfileRequest
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_can_open_their_own_profile(): void
    {
        foreach (['admin', 'telecaller', 'salesperson'] as $role) {
            $user = User::factory()->role($role)->create();

            $this->actingAs($user)->get('/account')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Account/Edit')
                ->where('account.email', $user->email)
                ->where('account.mobile_number', $user->mobile_number)
                ->where('access.role', config("crm.role_words.{$role}"))
                // there is no role, permission or status key in what the form edits
                ->missing('account.role')
                ->missing('account.permissions')
                ->missing('account.is_active'));
        }
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/account')->assertRedirect(route('login'));
        $this->put('/account', [])->assertRedirect(route('login'));
    }

    public function test_the_permissions_shown_are_the_ones_in_force(): void
    {
        $sales = User::factory()->role('salesperson')->create(['permissions' => null]);

        $this->actingAs($sales)->get('/account')->assertInertia(fn (Assert $page) => $page
            ->where('access.permissions', fn ($list) => collect($list)
                ->firstWhere('label', 'Can see all leads')['on'] === false
                && collect($list)->firstWhere('label', 'Can add leads')['on'] === true));
    }

    /* ---------------- editing ---------------- */

    public function test_a_user_can_change_their_name_email_and_mobile(): void
    {
        $user = User::factory()->role('telecaller')->create();
        $hash = $user->password;

        $this->actingAs($user)->put('/account', $this->payload($user, [
            'first_name' => 'Tara', 'last_name' => 'Iyer',
            'email' => 'tara.new@example.test', 'mobile_number' => '9876512345',
        ]))->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Profile updated.');

        $user->refresh();
        $this->assertSame('Tara', $user->first_name);
        $this->assertSame('Iyer', $user->last_name);
        $this->assertSame('tara.new@example.test', $user->email);
        $this->assertSame('9876512345', $user->mobile_number);
        // blank password fields: the hash is byte-identical
        $this->assertSame($hash, $user->password);
    }

    /** Saving your own unchanged email and number is not a clash with yourself. */
    public function test_uniqueness_ignores_the_current_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/account', $this->payload($user))->assertSessionHasNoErrors();
    }

    public function test_email_and_mobile_cannot_take_somebody_elses_including_a_deleted_user(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create(['email' => 'other@example.test', 'mobile_number' => '9876500111']);
        $gone  = User::factory()->create(['email' => 'gone@example.test', 'mobile_number' => '9876500222']);
        $gone->delete();

        foreach ([['other@example.test', '9876500111'], ['gone@example.test', '9876500222']] as [$email, $mobile]) {
            $this->actingAs($user)->put('/account', $this->payload($user, ['email' => $email]))
                ->assertSessionHasErrors(['email' => 'That email is already in use.']);

            $this->actingAs($user)->put('/account', $this->payload($user, ['mobile_number' => $mobile]))
                ->assertSessionHasErrors(['mobile_number' => 'That mobile number is already in use.']);
        }

        $this->assertNotSame('other@example.test', $user->fresh()->email);
    }

    /* ---------------- the admin's columns ---------------- */

    public function test_a_salesperson_cannot_change_their_own_role_or_permissions(): void
    {
        $sales = User::factory()->role('salesperson')->create(['permissions' => null]);

        $this->actingAs($sales)->put('/account', $this->payload($sales, [
            'first_name'      => 'Promoted',
            'role'            => 'admin',
            'permissions'     => ['see_all_leads' => true, 'delete_leads' => true],
            'is_active'       => 1,
            'approval_status' => 'approved',
        ]))->assertSessionHasErrors([
            'role'            => 'Your role is set by an administrator.',
            'permissions'     => 'Your permissions are set by an administrator.',
            'is_active'       => 'Your account status is set by an administrator.',
            'approval_status' => 'Your approval status is set by an administrator.',
        ]);

        $sales->refresh();
        $this->assertSame('salesperson', $sales->role);
        $this->assertNull($sales->permissions);
        $this->assertFalse($sales->can_('see_all_leads'));
        $this->assertFalse($sales->isAdmin());
        // refused as a whole, not partly applied
        $this->assertNotSame('Promoted', $sales->first_name);
    }

    /** One field at a time, so no single one is only caught by keeping company with another. */
    public function test_each_admin_column_is_refused_on_its_own(): void
    {
        $tele = User::factory()->role('telecaller')->create();

        foreach ([
            'role'            => 'salesperson',
            'permissions'     => ['add_leads' => true],
            'is_active'       => 0,
            'approval_status' => 'rejected',
        ] as $field => $value) {
            $this->actingAs($tele)->put('/account', $this->payload($tele, [$field => $value]))
                ->assertSessionHasErrors($field);
        }

        $tele->refresh();
        $this->assertSame('telecaller', $tele->role);
        $this->assertNull($tele->permissions);
        $this->assertTrue($tele->is_active);
        $this->assertSame('approved', $tele->approval_status);
        $this->assertFalse($tele->can_('add_leads'));
    }

    /** A column nobody thought to prohibit still cannot ride in on the request. */
    public function test_nothing_outside_the_five_columns_is_written(): void
    {
        $user = User::factory()->create();
        $id   = $user->id;

        $this->actingAs($user)->put('/account', $this->payload($user, [
            'id' => 999, 'deleted_at' => now()->toDateTimeString(), 'remember_token' => 'x', 'name' => 'Nope',
        ]))->assertSessionHasNoErrors();

        $this->assertNotNull(User::find($id));
        $this->assertFalse(User::find($id)->trashed());
        $this->assertNotSame('x', User::find($id)->remember_token);
    }

    /* ---------------- the password ---------------- */

    public function test_changing_the_password_needs_the_current_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/account', $this->payload($user, [
            'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
        ]))->assertSessionHasErrors(['current_password' => 'Enter your current password to set a new one.']);

        $this->actingAs($user)->put('/account', $this->payload($user, [
            'current_password' => 'not-it',
            'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
        ]))->assertSessionHasErrors(['current_password' => 'That is not your current password.']);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_a_new_password_is_at_least_eight_characters_and_confirmed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/account', $this->payload($user, [
            'current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short',
        ]))->assertSessionHasErrors(['password' => 'The password must be at least 8 characters.']);

        $this->actingAs($user)->put('/account', $this->payload($user, [
            'current_password' => 'password', 'password' => 'brand-new-pass', 'password_confirmation' => 'other-pass',
        ]))->assertSessionHasErrors(['password' => 'The two passwords do not match.']);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_the_password_changes_and_the_new_one_signs_in(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/account', $this->payload($user, [
            'current_password' => 'password',
            'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
        ]))->assertSessionHasNoErrors()->assertSessionHas('success', 'Profile and password updated.');

        // hashed once, by the cast — not stored plain, not hashed twice
        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));

        $this->post('/logout');
        $this->post('/login', ['login' => $user->email, 'password' => 'brand-new-pass']);
        $this->assertAuthenticatedAs($user->fresh());
    }

    /* ---------------- no deleting ---------------- */

    public function test_there_is_no_way_to_delete_your_own_account(): void
    {
        $user = User::factory()->role('salesperson')->create();

        $this->actingAs($user)->delete('/account', ['password' => 'password'])->assertStatus(405);
        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertNotFound();
        $this->actingAs($user)->get('/profile')->assertNotFound();
        $this->actingAs($user)->patch('/profile', [])->assertNotFound();

        $this->assertNotNull($user->fresh());
        $this->assertFalse($user->fresh()->trashed());
    }

    /* ---------------- fixtures ---------------- */

    private function payload(User $user, array $overrides = []): array
    {
        return array_merge([
            'first_name'    => $user->first_name,
            'last_name'     => $user->last_name,
            'email'         => $user->email,
            'mobile_number' => $user->mobile_number,
            'current_password'      => '',
            'password'              => '',
            'password_confirmation' => '',
        ], $overrides);
    }
}
