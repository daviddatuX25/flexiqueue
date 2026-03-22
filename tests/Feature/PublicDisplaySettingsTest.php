<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per plan: POST /api/public/display-settings — PIN required; no auth; rate-limited.
 * Verify PIN against active program's supervisors or admin; update program settings and broadcast.
 */
class PublicDisplaySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_program_id_returns_422(): void
    {
        $response = $this->postJson('/api/public/display-settings', [
            'pin' => '123456',
            'enable_display_hid_barcode' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.program_id.0', 'The program id field is required.');
    }

    public function test_invalid_pin_returns_401(): void
    {
        $admin = User::factory()->admin()->withOverridePin('123456')->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->postJson('/api/public/display-settings', [
            'program_id' => $program->id,
            'pin' => '999999',
            'enable_display_hid_barcode' => false,
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Invalid PIN.');
    }

    public function test_valid_supervisor_pin_updates_settings_returns_200(): void
    {
        $admin = User::factory()->admin()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
            'settings' => [
                'enable_display_hid_barcode' => true,
                'enable_public_triage_hid_barcode' => true,
                'display_audio_muted' => false,
                'display_audio_volume' => 1,
            ],
        ]);
        $supervisor = User::factory()->supervisor()->withOverridePin('123456')->create();
        $program->supervisedBy()->attach($supervisor->id);

        $response = $this->postJson('/api/public/display-settings', [
            'program_id' => $program->id,
            'pin' => '123456',
            'enable_display_hid_barcode' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('enable_display_hid_barcode', false);
        $response->assertJsonPath('enable_public_triage_hid_barcode', true);

        $program->refresh();
        $this->assertFalse($program->getEnableDisplayHidBarcode());
        $this->assertTrue($program->getEnablePublicTriageHidBarcode());
    }

    public function test_valid_admin_pin_updates_settings_returns_200(): void
    {
        $admin = User::factory()->admin()->withOverridePin('654321')->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
            'settings' => [
                'display_audio_muted' => false,
                'display_audio_volume' => 0.8,
            ],
        ]);

        $response = $this->postJson('/api/public/display-settings', [
            'program_id' => $program->id,
            'pin' => '654321',
            'display_audio_muted' => true,
            'display_audio_volume' => 0.5,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('display_audio_muted', true);
        $response->assertJsonPath('display_audio_volume', 0.5);

        $program->refresh();
        $this->assertTrue($program->getDisplayAudioMuted());
        $this->assertSame(0.5, $program->getDisplayAudioVolume());
    }

    public function test_validation_requires_pin(): void
    {
        $admin = User::factory()->admin()->withOverridePin('123456')->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->postJson('/api/public/display-settings', [
            'program_id' => $program->id,
            'pin' => '12345', // 5 digits
            'enable_display_hid_barcode' => false,
        ]);

        $response->assertStatus(422);
    }

    public function test_logged_in_staff_same_site_can_update_without_pin(): void
    {
        $site = Site::create([
            'name' => 'Test Site',
            'slug' => 'test-site-'.Str::random(6),
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $staff = User::factory()->create(['site_id' => $site->id, 'role' => 'staff']);
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
            'settings' => [
                'display_audio_muted' => false,
                'display_audio_volume' => 1,
            ],
        ]);

        $response = $this->actingAs($staff)->postJson('/api/public/display-settings', [
            'program_id' => $program->id,
            'display_audio_muted' => true,
            'display_audio_volume' => 0.4,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('display_audio_muted', true);
        $response->assertJsonPath('display_audio_volume', 0.4);
    }

    public function test_logged_in_staff_other_site_still_requires_pin(): void
    {
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a-'.Str::random(6),
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b-'.Str::random(6),
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->withOverridePin('123456')->create(['site_id' => $siteA->id]);
        $staffB = User::factory()->create(['site_id' => $siteB->id, 'role' => 'staff']);
        $program = Program::create([
            'site_id' => $siteA->id,
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($staffB)->postJson('/api/public/display-settings', [
            'program_id' => $program->id,
            'display_audio_muted' => true,
        ]);

        $response->assertStatus(422);
    }

    public function test_valid_pin_updates_enable_display_camera_scanner(): void
    {
        $admin = User::factory()->admin()->withOverridePin('123456')->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
            'settings' => [
                'enable_display_camera_scanner' => true,
            ],
        ]);

        $response = $this->postJson('/api/public/display-settings', [
            'program_id' => $program->id,
            'pin' => '123456',
            'enable_display_camera_scanner' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('enable_display_camera_scanner', false);

        $program->refresh();
        $this->assertFalse($program->settings()->getEnableDisplayCameraScanner());
    }

    public function test_valid_pin_updates_enable_public_triage_camera_scanner(): void
    {
        $admin = User::factory()->admin()->withOverridePin('123456')->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
            'settings' => [
                'enable_public_triage_camera_scanner' => true,
            ],
        ]);

        $response = $this->postJson('/api/public/display-settings', [
            'program_id' => $program->id,
            'pin' => '123456',
            'enable_public_triage_camera_scanner' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('enable_public_triage_camera_scanner', false);

        $program->refresh();
        $this->assertFalse($program->settings()->getEnablePublicTriageCameraScanner());
    }
}
