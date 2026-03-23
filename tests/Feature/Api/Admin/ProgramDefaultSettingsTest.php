<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Site-scoped program defaults: GET/PUT /api/admin/program-default-settings (role:admin + site_id).
 */
class ProgramDefaultSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create([
            'name' => 'Default Site',
            'slug' => 'default',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->admin = User::factory()->admin()->create(['site_id' => $this->site->id]);
    }

    public function test_show_returns_default_settings(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/program-default-settings');

        $response->assertStatus(200);
        $response->assertJsonStructure(['settings' => [
            'no_show_timer_seconds',
            'max_no_show_attempts',
            'require_permission_before_override',
            'priority_first',
            'balance_mode',
            'station_selection_mode',
            'alternate_ratio',
        ]]);
        $settings = $response->json('settings');
        $this->assertIsInt($settings['no_show_timer_seconds']);
        $this->assertSame(3, $settings['max_no_show_attempts']);
        $this->assertIsArray($settings['alternate_ratio']);
        $this->assertCount(2, $settings['alternate_ratio']);
    }

    public function test_update_saves_default_settings_for_site_row(): void
    {
        $response = $this->actingAs($this->admin)->putJson('/api/admin/program-default-settings', [
            'settings' => [
                'no_show_timer_seconds' => 30,
                'max_no_show_attempts' => 5,
                'require_permission_before_override' => false,
                'priority_first' => true,
                'balance_mode' => 'alternate',
                'station_selection_mode' => 'shortest_queue',
                'alternate_ratio' => [3, 1],
                'alternate_priority_first' => false,
                'display_scan_timeout_seconds' => 25,
                'display_audio_muted' => true,
                'display_audio_volume' => 0.7,
                'display_tts_repeat_count' => 2,
                'display_tts_repeat_delay_ms' => 2500,
                'allow_public_triage' => true,
                'allow_unverified_entry' => false,
                'identity_binding_mode' => 'required',
                'enable_display_hid_barcode' => false,
                'enable_public_triage_hid_barcode' => true,
                'enable_display_camera_scanner' => false,
                'tts' => [
                    'active_language' => 'fil',
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('settings.no_show_timer_seconds', 30);
        $response->assertJsonPath('settings.max_no_show_attempts', 5);
        $response->assertJsonPath('settings.require_permission_before_override', false);
        $response->assertJsonPath('settings.balance_mode', 'alternate');
        $response->assertJsonPath('settings.station_selection_mode', 'shortest_queue');
        $response->assertJsonPath('settings.alternate_ratio', [3, 1]);
        $response->assertJsonPath('settings.alternate_priority_first', false);
        $response->assertJsonPath('settings.display_scan_timeout_seconds', 25);
        $response->assertJsonPath('settings.display_audio_muted', true);
        $response->assertJsonPath('settings.display_audio_volume', 0.7);
        $response->assertJsonPath('settings.display_tts_repeat_count', 2);
        $response->assertJsonPath('settings.display_tts_repeat_delay_ms', 2500);
        $response->assertJsonPath('settings.allow_public_triage', true);
        $response->assertJsonPath('settings.allow_unverified_entry', false);
        $response->assertJsonPath('settings.identity_binding_mode', 'required');
        $response->assertJsonPath('settings.enable_display_hid_barcode', false);
        $response->assertJsonPath('settings.enable_public_triage_hid_barcode', true);
        $response->assertJsonPath('settings.enable_display_camera_scanner', false);
        $response->assertJsonPath('settings.tts.active_language', 'fil');

        $row = DB::table('program_default_settings')->where('site_id', $this->site->id)->first();
        $this->assertNotNull($row);
        $decoded = json_decode($row->settings, true);
        $this->assertSame(30, $decoded['no_show_timer_seconds']);
        $this->assertSame(5, $decoded['max_no_show_attempts']);
        $this->assertSame(25, $decoded['display_scan_timeout_seconds']);
        $this->assertTrue($decoded['display_audio_muted']);
        $this->assertSame(0.7, $decoded['display_audio_volume']);
        $this->assertSame(2, $decoded['display_tts_repeat_count']);
        $this->assertSame(2500, $decoded['display_tts_repeat_delay_ms']);
        $this->assertTrue($decoded['allow_public_triage']);
        $this->assertFalse($decoded['allow_unverified_entry']);
        $this->assertSame('required', $decoded['identity_binding_mode']);
        $this->assertFalse($decoded['enable_display_hid_barcode']);
        $this->assertTrue($decoded['enable_public_triage_hid_barcode']);
        $this->assertFalse($decoded['enable_display_camera_scanner']);
        $this->assertSame('fil', $decoded['tts']['active_language'] ?? null);

        $getResponse = $this->actingAs($this->admin)->getJson('/api/admin/program-default-settings');
        $getResponse->assertStatus(200);
        $getResponse->assertJsonPath('settings.max_no_show_attempts', 5);
    }

    public function test_update_rejects_max_no_show_attempts_out_of_range(): void
    {
        $responseZero = $this->actingAs($this->admin)->putJson('/api/admin/program-default-settings', [
            'settings' => [
                'no_show_timer_seconds' => 10,
                'max_no_show_attempts' => 0,
            ],
        ]);
        $responseZero->assertStatus(422);
        $responseZero->assertJsonValidationErrors(['settings.max_no_show_attempts']);

        $responseEleven = $this->actingAs($this->admin)->putJson('/api/admin/program-default-settings', [
            'settings' => [
                'no_show_timer_seconds' => 10,
                'max_no_show_attempts' => 11,
            ],
        ]);
        $responseEleven->assertStatus(422);
        $responseEleven->assertJsonValidationErrors(['settings.max_no_show_attempts']);
    }

    public function test_staff_cannot_access_returns_403(): void
    {
        $staff = User::factory()->create();
        $this->actingAs($staff)->getJson('/api/admin/program-default-settings')->assertStatus(403);
        $this->actingAs($staff)->putJson('/api/admin/program-default-settings', [
            'settings' => ['no_show_timer_seconds' => 15],
        ])->assertStatus(403);
    }

    public function test_super_admin_without_site_cannot_use_site_scoped_program_defaults(): void
    {
        $super = User::factory()->superAdmin()->create(['site_id' => null]);

        $this->actingAs($super)->getJson('/api/admin/program-default-settings')->assertStatus(403);
        $this->actingAs($super)->putJson('/api/admin/program-default-settings', [
            'settings' => ['no_show_timer_seconds' => 15],
        ])->assertStatus(403);
    }

    public function test_site_admin_update_does_not_change_other_site_defaults(): void
    {
        $otherSite = Site::create([
            'name' => 'Other',
            'slug' => 'other',
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $otherAdmin = User::factory()->admin()->create(['site_id' => $otherSite->id]);
        // Lazy-create site row (same as first GET would).
        $this->actingAs($otherAdmin)->getJson('/api/admin/program-default-settings')->assertStatus(200);

        $this->actingAs($this->admin)->putJson('/api/admin/program-default-settings', [
            'settings' => [
                'no_show_timer_seconds' => 88,
                'max_no_show_attempts' => 3,
            ],
        ])->assertStatus(200);

        $otherRow = DB::table('program_default_settings')->where('site_id', $otherSite->id)->first();
        $this->assertNotNull($otherRow);
        $otherDecoded = json_decode($otherRow->settings, true);
        $this->assertNotSame(88, $otherDecoded['no_show_timer_seconds'] ?? null);
    }
}
