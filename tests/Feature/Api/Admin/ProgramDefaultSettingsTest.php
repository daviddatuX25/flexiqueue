<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Per ISSUES-ELABORATION §2: GET/PUT program-default-settings. Admin only.
 */
class ProgramDefaultSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
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

    public function test_update_saves_default_settings(): void
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
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('settings.no_show_timer_seconds', 30);
        $response->assertJsonPath('settings.max_no_show_attempts', 5);
        $response->assertJsonPath('settings.require_permission_before_override', false);
        $response->assertJsonPath('settings.balance_mode', 'alternate');
        $response->assertJsonPath('settings.station_selection_mode', 'shortest_queue');
        $response->assertJsonPath('settings.alternate_ratio', [3, 1]);

        $row = DB::table('program_default_settings')->first();
        $this->assertNotNull($row);
        $decoded = json_decode($row->settings, true);
        $this->assertSame(30, $decoded['no_show_timer_seconds']);
        $this->assertSame(5, $decoded['max_no_show_attempts']);

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
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->getJson('/api/admin/program-default-settings')->assertStatus(403);
        $this->actingAs($staff)->putJson('/api/admin/program-default-settings', [
            'settings' => ['no_show_timer_seconds' => 15],
        ])->assertStatus(403);
    }
}
