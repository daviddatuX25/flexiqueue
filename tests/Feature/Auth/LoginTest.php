<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Per 08-API-SPEC-PHASE1 §1.1–1.2, 05-SECURITY-CONTROLS §2.1–2.3: session login/logout, role redirect, rate limit.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN_THROTTLE_PREFIX = 'login:';

    public function test_login_with_valid_credentials_redirects_admin_to_dashboard(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_with_valid_credentials_redirects_staff_to_station(): void
    {
        $user = User::factory()->create(['role' => 'staff']);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('station'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_with_valid_credentials_redirects_supervisor_to_station(): void
    {
        $user = User::factory()->supervisor()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('station'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_with_invalid_credentials_redirects_with_validation_errors(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_with_inactive_user_redirects_with_invalid_credentials_message(): void
    {
        $user = User::factory()->inactive()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_rate_limited_after_five_attempts_redirects_with_error(): void
    {
        $key = self::LOGIN_THROTTLE_PREFIX.'127.0.0.1';
        RateLimiter::clear($key);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => 'someone@example.com',
                'password' => 'wrong',
            ]);
        }

        $response = $this->post('/login', [
            'email' => 'someone@example.com',
            'password' => 'wrong',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_logout_invalidates_session_and_redirects_to_login(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /** Logout sets availability to 'away' so queue/process fallbacks (staff_online, etc.) exclude this user. */
    public function test_logout_sets_availability_to_away(): void
    {
        $user = User::factory()->create(['availability_status' => 'available']);

        $this->actingAs($user)->post(route('logout'));

        $user->refresh();
        $this->assertSame('away', $user->availability_status);
    }

    public function test_guest_visiting_protected_route_redirects_to_login(): void
    {
        $response = $this->get(route('admin.dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_visiting_login_redirects_by_role(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($admin)->get(route('login'))->assertRedirect(route('admin.dashboard'));
        $this->actingAs($staff)->get(route('login'))->assertRedirect(route('station'));
    }

    public function test_login_requires_email_and_password(): void
    {
        $response = $this->post('/login', []);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['email', 'password']);
    }
}
