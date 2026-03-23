<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteOAuthUser;
use Tests\TestCase;

class GoogleOAuthCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_matches_recovery_gmail_creates_google_credential_and_redirects_staff(): void
    {
        config(['services.google.client_id' => 'test-client']);

        $user = User::factory()->create([
            'recovery_gmail' => 'staff@gmail.com',
        ]);

        $socialiteUser = new SocialiteOAuthUser;
        $socialiteUser->id = 'google-sub-abc';
        $socialiteUser->email = 'staff@gmail.com';
        $socialiteUser->name = 'Staff';

        Socialite::fake('google', $socialiteUser);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('station'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('user_credentials', [
            'user_id' => $user->id,
            'provider' => UserCredential::PROVIDER_GOOGLE,
            'identifier' => 'google-sub-abc',
        ]);
    }

    public function test_callback_unknown_google_account_redirects_to_login_with_message(): void
    {
        config(['services.google.client_id' => 'test-client']);

        User::factory()->create([
            'recovery_gmail' => 'known@gmail.com',
        ]);

        $socialiteUser = new SocialiteOAuthUser;
        $socialiteUser->id = 'google-sub-xyz';
        $socialiteUser->email = 'stranger@gmail.com';
        $socialiteUser->name = 'Stranger';

        Socialite::fake('google', $socialiteUser);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_callback_finds_user_by_existing_google_credential(): void
    {
        config(['services.google.client_id' => 'test-client']);

        $user = User::factory()->create([
            'recovery_gmail' => 'linked@gmail.com',
        ]);
        UserCredential::query()->create([
            'user_id' => $user->id,
            'provider' => UserCredential::PROVIDER_GOOGLE,
            'identifier' => 'existing-sub',
            'secret' => null,
        ]);

        $socialiteUser = new SocialiteOAuthUser;
        $socialiteUser->id = 'existing-sub';
        $socialiteUser->email = 'linked@gmail.com';
        $socialiteUser->name = 'Linked';

        Socialite::fake('google', $socialiteUser);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('station'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, UserCredential::query()->where('provider', UserCredential::PROVIDER_GOOGLE)->where('user_id', $user->id)->count());
    }

    public function test_auth_google_redirect_returns_404_when_oauth_not_configured(): void
    {
        config(['services.google.client_id' => null]);

        $response = $this->get(route('auth.google.redirect'));

        $response->assertNotFound();
    }
}
