<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per plan: POST /api/public/display-settings — PIN required; no auth; rate-limited.
 * Verify PIN against active program's supervisors or admin; update program settings and broadcast.
 */
class PublicDisplaySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_active_program_returns_400(): void
    {
        $response = $this->postJson('/api/public/display-settings', [
            'pin' => '123456',
            'enable_display_hid_barcode' => false,
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'No active program.');
    }

    public function test_invalid_pin_returns_401(): void
    {
        $admin = User::factory()->admin()->withOverridePin('123456')->create();
        Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->postJson('/api/public/display-settings', [
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
        Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $response = $this->postJson('/api/public/display-settings', [
            'pin' => '12345', // 5 digits
            'enable_display_hid_barcode' => false,
        ]);

        $response->assertStatus(422);
    }
}
