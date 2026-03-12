<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Per PIN-QR-AUTHORIZATION-SYSTEM AUTH-2: Profile preset PIN/QR API.
 */
class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_override_pin_requires_current_password(): void
    {
        $user = User::factory()->admin()->create(['password' => Hash::make('password')]);

        $response = $this->actingAs($user)->putJson('/api/profile/override-pin', [
            'current_password' => 'wrong',
            'new_pin' => '123456',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('current_password');
    }

    public function test_update_override_pin_validates_new_pin_format(): void
    {
        $user = User::factory()->admin()->create(['password' => Hash::make('password')]);

        $response = $this->actingAs($user)->putJson('/api/profile/override-pin', [
            'current_password' => 'password',
            'new_pin' => '12345',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('new_pin');
    }

    public function test_update_override_pin_succeeds_with_valid_input(): void
    {
        $user = User::factory()->admin()->create(['password' => Hash::make('password')]);

        $response = $this->actingAs($user)->putJson('/api/profile/override-pin', [
            'current_password' => 'password',
            'new_pin' => '654321',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Override PIN updated.']);
        $user->refresh();
        $this->assertTrue(Hash::check('654321', $user->override_pin));
    }

    public function test_show_override_qr_returns_has_preset_qr_false_when_not_set(): void
    {
        $user = User::factory()->admin()->create(['override_qr_token' => null]);

        $response = $this->actingAs($user)->getJson('/api/profile/override-qr');

        $response->assertStatus(200);
        $response->assertJsonPath('has_preset_qr', false);
    }

    public function test_show_override_qr_returns_has_preset_qr_true_after_regenerate(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->postJson('/api/profile/override-qr/regenerate');

        $response = $this->actingAs($user)->getJson('/api/profile/override-qr');
        $response->assertStatus(200);
        $response->assertJsonPath('has_preset_qr', true);
    }

    public function test_regenerate_override_qr_returns_qr_data_uri_and_saves_hash(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->postJson('/api/profile/override-qr/regenerate');

        $response->assertStatus(200);
        $response->assertJsonStructure(['qr_data_uri', 'message']);
        $this->assertStringStartsWith('data:image/', $response->json('qr_data_uri'));
        $user->refresh();
        $this->assertNotNull($user->override_qr_token);
    }

    public function test_update_password_succeeds_with_valid_input(): void
    {
        $user = User::factory()->admin()->create(['password' => Hash::make('password')]);

        $response = $this->actingAs($user)->putJson('/api/profile/password', [
            'current_password' => 'password',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Password updated.']);
        $this->assertTrue(Hash::check('newsecret123', $user->fresh()->password));
    }

    public function test_update_password_requires_current_password(): void
    {
        $user = User::factory()->admin()->create(['password' => Hash::make('password')]);

        $response = $this->actingAs($user)->putJson('/api/profile/password', [
            'current_password' => 'wrong',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('current_password');
    }

    public function test_update_avatar_succeeds_with_valid_image(): void
    {
        Storage::fake('public');
        $user = User::factory()->admin()->create();
        $file = UploadedFile::fake()->image('avatar.jpg', 100, 100)->size(100);

        $response = $this->actingAs($user)->post('/api/profile/avatar', [
            'avatar' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['avatar_url', 'message']);
        $user->refresh();
        $this->assertNotNull($user->avatar_path);
        $this->assertStringContainsString('avatars/', $response->json('avatar_url'));
    }

    public function test_update_avatar_validates_file_type(): void
    {
        Storage::fake('public');
        $user = User::factory()->admin()->create();
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($user)->post('/api/profile/avatar', [
            'avatar' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('avatar');
    }

    public function test_update_avatar_requires_auth(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('avatar.jpg', 100, 100);

        $response = $this->post('/api/profile/avatar', [
            'avatar' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(401);
    }

    public function test_profile_endpoints_require_auth(): void
    {
        $this->putJson('/api/profile/override-pin', [
            'current_password' => 'password',
            'new_pin' => '123456',
        ])->assertStatus(401);

        $this->getJson('/api/profile/override-qr')->assertStatus(401);
        $this->postJson('/api/profile/override-qr/regenerate')->assertStatus(401);
        $this->putJson('/api/profile/password', [
            'current_password' => 'password',
            'password' => 'newpass',
            'password_confirmation' => 'newpass',
        ])->assertStatus(401);

        Storage::fake('public');
        $file = UploadedFile::fake()->image('avatar.jpg', 100, 100);
        $this->post('/api/profile/avatar', ['avatar' => $file], ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_triage_settings_get_returns_preferences(): void
    {
        $user = User::factory()->create([
            'role' => 'staff',
            'staff_triage_allow_hid_barcode' => true,
            'staff_triage_allow_camera_scanner' => false,
        ]);

        $response = $this->actingAs($user)->getJson('/api/profile/triage-settings');

        $response->assertStatus(200);
        $response->assertJsonPath('allow_hid_barcode', true);
        $response->assertJsonPath('allow_camera_scanner', false);
    }

    public function test_triage_settings_put_updates_preferences(): void
    {
        $user = User::factory()->create([
            'role' => 'staff',
            'staff_triage_allow_hid_barcode' => true,
            'staff_triage_allow_camera_scanner' => true,
        ]);

        $response = $this->actingAs($user)->putJson('/api/profile/triage-settings', [
            'allow_hid_barcode' => false,
            'allow_camera_scanner' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('allow_hid_barcode', false);
        $response->assertJsonPath('allow_camera_scanner', false);

        $user->refresh();
        $this->assertFalse($user->staff_triage_allow_hid_barcode);
        $this->assertFalse($user->staff_triage_allow_camera_scanner);
    }

    public function test_triage_settings_requires_auth(): void
    {
        $this->getJson('/api/profile/triage-settings')->assertStatus(401);
        $this->putJson('/api/profile/triage-settings', [
            'allow_hid_barcode' => false,
        ])->assertStatus(401);
    }
}
