<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProgramPlatformDefaultSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

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
        $this->superAdmin = User::factory()->superAdmin()->create(['site_id' => null]);
    }

    public function test_super_admin_can_show_and_update_platform_program_defaults(): void
    {
        $response = $this->actingAs($this->superAdmin)->getJson('/api/admin/program-platform-default-settings');

        $response->assertStatus(200);
        $response->assertJsonStructure(['settings' => [
            'no_show_timer_seconds',
            'max_no_show_attempts',
        ]]);

        $put = $this->actingAs($this->superAdmin)->putJson('/api/admin/program-platform-default-settings', [
            'settings' => [
                'no_show_timer_seconds' => 42,
                'max_no_show_attempts' => 4,
                'balance_mode' => 'fifo',
                'tts' => ['active_language' => 'en'],
            ],
        ]);

        $put->assertStatus(200);
        $put->assertJsonPath('settings.no_show_timer_seconds', 42);

        $row = DB::table('program_default_settings')->whereNull('site_id')->first();
        $this->assertNotNull($row);
        $decoded = json_decode($row->settings, true);
        $this->assertSame(42, $decoded['no_show_timer_seconds']);
    }

    public function test_site_admin_cannot_access_platform_program_defaults(): void
    {
        $admin = User::factory()->admin()->create(['site_id' => $this->site->id]);

        $this->actingAs($admin)->getJson('/api/admin/program-platform-default-settings')->assertStatus(403);
        $this->actingAs($admin)->putJson('/api/admin/program-platform-default-settings', [
            'settings' => ['no_show_timer_seconds' => 15],
        ])->assertStatus(403);
    }
}
