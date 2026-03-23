<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_reset_notification_to_recovery_gmail(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'username' => 'staff_one',
            'recovery_gmail' => 'recover@gmail.com',
        ]);

        $response = $this->post(route('password.email'), [
            'username' => 'staff_one',
        ]);

        $response->assertSessionHas('status');
        $response->assertRedirect();
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_does_not_send_when_no_recovery_gmail(): void
    {
        Notification::fake();

        User::factory()->create([
            'username' => 'no_recovery',
            'recovery_gmail' => null,
        ]);

        $response = $this->post(route('password.email'), [
            'username' => 'no_recovery',
        ]);

        $response->assertSessionHas('status');
        Notification::assertNothingSent();
    }

    public function test_reset_password_updates_password_and_allows_login(): void
    {
        $user = User::factory()->create([
            'username' => 'reset_me',
            'recovery_gmail' => 'recover@gmail.com',
            'password' => Hash::make('old-password'),
        ]);

        $plainToken = Password::broker()->createToken($user);

        $response = $this->post(route('password.store'), [
            'username' => 'reset_me',
            'token' => $plainToken,
            'password' => 'new-secure-password-99',
            'password_confirmation' => 'new-secure-password-99',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue(Hash::check('new-secure-password-99', $user->password));

        $this->assertTrue(Auth::attempt([
            'username' => 'reset_me',
            'password' => 'new-secure-password-99',
        ]));
    }
}
