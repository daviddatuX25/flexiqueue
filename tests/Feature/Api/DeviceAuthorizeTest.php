<?php

namespace Tests\Feature\Api;

use App\Models\DeviceAuthorization;
use App\Models\Program;
use App\Models\Site;
use App\Models\User;
use App\Support\DeviceLock;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Per plan Step 5: POST /api/public/device-authorize (PIN/QR) for public display/triage.
 */
class DeviceAuthorizeTest extends TestCase
{
    use RefreshDatabase;

    private function defaultSite(): Site
    {
        return Site::firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default', 'api_key_hash' => Hash::make(Str::random(40)), 'settings' => [], 'edge_settings' => []]
        );
    }

    public function test_device_authorize_requires_program_id_and_pin(): void
    {
        $response = $this->postJson('/api/public/device-authorize', []);

        $response->assertStatus(422);
    }

    public function test_device_authorize_returns_401_for_invalid_pin(): void
    {
        $site = $this->defaultSite();
        $user = User::factory()->create(['site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $supervisor = User::factory()->create([
            'site_id' => $site->id,
            'override_pin' => Hash::make('123456'),
        ]);
        $this->grantProgramTeamSuperviseForTests($supervisor, $program);

        $response = $this->postJson('/api/public/device-authorize', [
            'program_id' => $program->id,
            'pin' => '999999',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Invalid PIN or QR code.');
    }

    public function test_device_authorize_returns_200_and_sets_cookie_for_valid_supervisor_pin(): void
    {
        $site = $this->defaultSite();
        $user = User::factory()->create(['site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $supervisor = User::factory()->create([
            'site_id' => $site->id,
            'override_pin' => Hash::make('123456'),
        ]);
        $this->grantProgramTeamSuperviseForTests($supervisor, $program);

        $response = $this->postJson('/api/public/device-authorize', [
            'program_id' => $program->id,
            'pin' => '123456',
            'allow_persistent' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Device authorized.');
        $response->assertJsonPath('scope', DeviceAuthorization::SCOPE_SESSION);
        $cookieName = 'device_auth_'.$program->id;
        $response->assertCookie($cookieName);
        $this->assertDatabaseHas('device_authorizations', [
            'program_id' => $program->id,
            'scope' => DeviceAuthorization::SCOPE_SESSION,
        ]);
    }

    public function test_device_authorize_returns_400_for_inactive_program(): void
    {
        $site = $this->defaultSite();
        $user = User::factory()->create(['site_id' => $site->id]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Test',
            'description' => null,
            'is_active' => false,
            'created_by' => $user->id,
        ]);
        $supervisor = User::factory()->create([
            'site_id' => $site->id,
            'override_pin' => Hash::make('123456'),
        ]);
        $this->grantProgramTeamSuperviseForTests($supervisor, $program);

        $response = $this->postJson('/api/public/device-authorize', [
            'program_id' => $program->id,
            'pin' => '123456',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'Program not found or not active.');
    }

    public function test_device_authorize_returns_403_when_pin_valid_but_user_lacks_public_device_authorize(): void
    {
        Role::findByName('staff', PermissionCatalog::guardName())
            ?->revokePermissionTo(PermissionCatalog::PUBLIC_DEVICE_AUTHORIZE);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $site = $this->defaultSite();
        $user = User::factory()->create([
            'site_id' => $site->id,
            'override_pin' => null,
        ]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $supervisor = User::factory()->create([
            'site_id' => $site->id,
            'override_pin' => Hash::make('987654'),
        ]);
        $this->grantProgramTeamSuperviseForTests($supervisor, $program);

        $response = $this->postJson('/api/public/device-authorize', [
            'program_id' => $program->id,
            'pin' => '987654',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Unauthorized.');
    }

    public function test_device_unlock_with_auth_returns_403_when_pin_valid_but_user_lacks_public_device_authorize(): void
    {
        Role::findByName('staff', PermissionCatalog::guardName())
            ?->revokePermissionTo(PermissionCatalog::PUBLIC_DEVICE_AUTHORIZE);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $site = $this->defaultSite();
        $user = User::factory()->create([
            'site_id' => $site->id,
            'override_pin' => null,
        ]);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $supervisor = User::factory()->create([
            'site_id' => $site->id,
            'override_pin' => Hash::make('987654'),
        ]);
        $this->grantProgramTeamSuperviseForTests($supervisor, $program);

        $response = $this->withSession([
            DeviceLock::SESSION_KEY => [
                'site_slug' => $site->slug,
                'program_slug' => $program->slug,
                'device_type' => DeviceLock::TYPE_DISPLAY,
            ],
        ])->postJson('/api/public/device-unlock-with-auth', [
            'pin' => '987654',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Unauthorized.');
    }
}
