<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Program;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProgramDefaultSettingsApplyToProgramTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->admin = User::factory()->admin()->create(['site_id' => $site->id]);
    }

    public function test_applying_defaults_to_program_updates_program_settings_and_downstream_props(): void
    {
        $this->actingAs($this->admin);

        // Seed global defaults.
        $this->putJson('/api/admin/program-default-settings', [
            'settings' => [
                'no_show_timer_seconds' => 45,
                'max_no_show_attempts' => 4,
                'require_permission_before_override' => true,
                'priority_first' => false,
                'balance_mode' => 'alternate',
                'station_selection_mode' => 'least_busy',
                'alternate_ratio' => [2, 1],
                'alternate_priority_first' => false,
                'display_scan_timeout_seconds' => 15,
                'display_audio_muted' => true,
                'display_audio_volume' => 0.5,
                'display_tts_repeat_count' => 3,
                'display_tts_repeat_delay_ms' => 3000,
                'allow_public_triage' => true,
                'allow_unverified_entry' => false,
                'identity_binding_mode' => 'required',
                'enable_display_hid_barcode' => false,
                'enable_public_triage_hid_barcode' => true,
                'enable_display_camera_scanner' => false,
                'tts' => [
                    'active_language' => 'ilo',
                ],
            ],
        ])->assertStatus(200);

        // Create a program with empty settings.
        /** @var Program $program */
        $program = Program::create([
            'site_id' => $this->admin->site_id,
            'name' => 'Test Program',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
            'settings' => [],
        ]);

        // Mimic the Program Show "Save" payload after applying defaults:
        $defaults = $this->getJson('/api/admin/program-default-settings')
            ->assertStatus(200)
            ->json('settings');

        $response = $this->putJson("/api/admin/programs/{$program->id}", [
            'name' => $program->name,
            'description' => $program->description,
            'settings' => $defaults,
        ]);

        $response->assertStatus(200);

        $program->refresh();
        $this->assertSame(45, $program->settings['no_show_timer_seconds']);
        $this->assertSame(4, $program->settings['max_no_show_attempts']);
        $this->assertSame('alternate', $program->settings['balance_mode']);
        $this->assertSame('least_busy', $program->settings['station_selection_mode']);
        $this->assertSame([2, 1], $program->settings['alternate_ratio']);
        $this->assertFalse($program->settings['alternate_priority_first']);
        $this->assertSame(15, $program->settings['display_scan_timeout_seconds']);
        $this->assertTrue($program->settings['display_audio_muted']);
        $this->assertSame(0.5, $program->settings['display_audio_volume']);
        $this->assertSame(3, $program->settings['display_tts_repeat_count']);
        $this->assertSame(3000, $program->settings['display_tts_repeat_delay_ms']);
        $this->assertTrue($program->settings['allow_public_triage']);
        $this->assertFalse($program->settings['allow_unverified_entry']);
        $this->assertSame('required', $program->settings['identity_binding_mode']);
        $this->assertFalse($program->settings['enable_display_hid_barcode']);
        $this->assertTrue($program->settings['enable_public_triage_hid_barcode']);
        $this->assertFalse($program->settings['enable_display_camera_scanner']);
        $this->assertSame('ilo', $program->settings['tts']['active_language'] ?? null);

        // Verify downstream public triage page props now reflect updated settings.
        DB::table('programs')->where('id', $program->id)->update(['is_active' => true]);

        $response = $this->get('/public/triage/'.$program->id);
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Triage/PublicStart')
            ->where('allowed', true)
            ->where('identity_binding_mode', 'required')
            ->where('allow_unverified_entry', false)
            ->where('display_scan_timeout_seconds', 15)
            ->where('enable_public_triage_hid_barcode', true)
        );
    }
}

