<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteOAuthUser;
use Tests\TestCase;

class GoogleOAuthLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_google_link_requires_authentication(): void
    {
        config(['services.google.client_id' => 'test-client']);

        $this->get(route('auth.google.link'))->assertRedirect(route('login'));
    }

    public function test_auth_google_link_redirects_when_configured(): void
    {
        config(['services.google.client_id' => 'test-client']);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('auth.google.link'));

        $response->assertRedirect();
    }

    public function test_callback_link_intent_attaches_google_for_logged_in_user(): void
    {
        config(['services.google.client_id' => 'test-client']);

        $user = User::factory()->create([
            'recovery_gmail' => 'link@gmail.com',
        ]);

        $socialiteUser = new SocialiteOAuthUser;
        $socialiteUser->id = 'google-sub-link-1';
        $socialiteUser->email = 'link@gmail.com';
        $socialiteUser->name = 'Link User';

        Socialite::fake('google', $socialiteUser);

        $response = $this->actingAs($user)
            ->withSession(['oauth_google_intent' => 'link', 'oauth_link_user_id' => $user->id])
            ->get(route('auth.google.callback'));

        $response->assertRedirect(route('profile'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('user_credentials', [
            'user_id' => $user->id,
            'provider' => UserCredential::PROVIDER_GOOGLE,
            'identifier' => 'google-sub-link-1',
        ]);
    }

    public function test_profile_unlink_google_returns_404_when_oauth_not_configured(): void
    {
        config(['services.google.client_id' => null]);

        $user = User::factory()->create();

        $this->actingAs($user)->deleteJson('/api/profile/google')->assertStatus(404);
    }

    public function test_profile_unlink_google_removes_credential_when_configured(): void
    {
        config(['services.google.client_id' => 'test-client']);

        $user = User::factory()->create();
        UserCredential::query()->create([
            'user_id' => $user->id,
            'provider' => UserCredential::PROVIDER_GOOGLE,
            'identifier' => 'sub-xyz',
            'secret' => null,
        ]);

        $this->actingAs($user)->deleteJson('/api/profile/google')->assertOk();

        $this->assertSame(0, UserCredential::query()
            ->where('user_id', $user->id)
            ->where('provider', UserCredential::PROVIDER_GOOGLE)
            ->count());
    }
}
