<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\UserRole;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PATCH /api/admin/sites/{site}/default — set default site for display/triage. Super_admin only.
 */
class SiteSetDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_set_default_site(): void
    {
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
            'is_default' => true,
        ]);
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
            'is_default' => false,
        ]);
        $superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin, 'site_id' => null]);

        $response = $this->actingAs($superAdmin)->patchJson("/api/admin/sites/{$siteB->id}/default");

        $response->assertStatus(200);
        $response->assertJsonPath('site.is_default', true);
        $response->assertJsonPath('site.id', $siteB->id);

        $siteA->refresh();
        $siteB->refresh();
        $this->assertFalse($siteA->is_default);
        $this->assertTrue($siteB->is_default);

        SiteResolver::clearDefaultCache();
        $default = SiteResolver::default();
        $this->assertSame($siteB->id, $default->id);
    }

    public function test_admin_cannot_set_default_site(): void
    {
        $site = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        $response = $this->actingAs($admin)->patchJson("/api/admin/sites/{$site->id}/default");

        $response->assertStatus(403);
    }
}
