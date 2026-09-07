<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two auth routes this application still has.
 *
 * Register, forgot/reset password, email verification and confirm password were
 * Breeze scaffolding with no pages behind them and no place in a CRM where an
 * admin makes the accounts; they were deleted, and the last two tests here say
 * so. Sign in and sign out are the whole surface now.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertStatus(200);
    }

    /**
     * `login`, not `email`: LoginRequest takes an email address or a mobile
     * number in one field and picks the column from the shape of what it got.
     */
    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'login'    => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_authenticate_with_their_mobile_number(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'login'    => $user->mobile_number,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'login'    => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    /** is_active is part of the credentials, so a switched-off account cannot sign in. */
    public function test_a_deactivated_user_can_not_authenticate(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', [
            'login'    => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    /**
     * Straight to /login, not to '/'. That route redirects on to /dashboard,
     * and the extra hop would age the "signed out" flash out before the login
     * page ever rendered — see AuthenticatedSessionController::destroy().
     */
    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }

    /** The doors that were closed. A 404 is the whole assertion. */
    public function test_the_breeze_routes_are_gone(): void
    {
        foreach (['/register', '/forgot-password', '/reset-password/token', '/verify-email', '/confirm-password'] as $url) {
            $this->get($url)->assertStatus(404, "$url must not resolve");
        }

        $this->post('/register', [])->assertNotFound();
        $this->post('/forgot-password', [])->assertNotFound();
    }

    /** Including the profile routes, of which DELETE was the dangerous one. */
    public function test_the_profile_routes_are_gone(): void
    {
        $user = User::factory()->role('admin')->create();

        $this->actingAs($user)->get('/profile')->assertNotFound();
        $this->actingAs($user)->patch('/profile', [])->assertNotFound();
        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertNotFound();

        $this->assertNotNull($user->fresh(), 'DELETE /profile must not reach the account any more');
    }
}
