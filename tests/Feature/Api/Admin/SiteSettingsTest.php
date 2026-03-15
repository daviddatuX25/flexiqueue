<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PATCH /api/admin/site/settings — admin-only endpoint for site settings (deprecated).
 */
class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_call_site_settings_endpoint(): void
    {
        $site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        $response = $this->actingAs($admin)->patchJson('/api/admin/site/settings', []);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'OK');
    }
}
