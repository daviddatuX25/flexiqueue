<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Site;
use App\Models\User;
use App\Services\SiteApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Per central-edge B.2: Site API (create, show, regenerate-key). RBAC: only super_admin may create; site admin sees only own site.
 */
class SiteControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $superAdmin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create([
            'name' => 'Default Site',
            'slug' => 'default',
            'api_key_hash' => Hash::make('x'),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->admin = User::factory()->admin()->create(['site_id' => $this->site->id]);
        $this->superAdmin = User::factory()->create(['role' => 'super_admin', 'site_id' => null]);
    }

    public function test_super_admin_store_creates_site_and_returns_raw_api_key_once(): void
    {
        $response = $this->actingAs($this->superAdmin)->postJson('/api/admin/sites', [
            'name' => 'Dagupan CSWDO',
            'slug' => 'mswdo-dagupan',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('site.name', 'Dagupan CSWDO');
        $response->assertJsonPath('site.slug', 'mswdo-dagupan');
        $apiKey = $response->json('api_key');
        $this->assertIsString($apiKey);
        $this->assertStringStartsWith('sk_live_', $apiKey);
        $this->assertGreaterThanOrEqual(40, strlen($apiKey));

        $this->assertDatabaseHas('sites', [
            'name' => 'Dagupan CSWDO',
            'slug' => 'mswdo-dagupan',
        ]);
        $site = Site::where('slug', 'mswdo-dagupan')->first();
        $this->assertNotNull($site->api_key_hash);
        $this->assertNotSame($apiKey, $site->api_key_hash);
        $this->assertTrue(Hash::check($apiKey, $site->api_key_hash));
    }

    public function test_show_site_does_not_expose_raw_api_key(): void
    {
        $response = $this->actingAs($this->admin)->getJson("/api/admin/sites/{$this->site->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('site.id', $this->site->id);
        $response->assertJsonPath('site.name', 'Default Site');
        $response->assertJsonPath('api_key_masked', 'sk_live_...****');
        $this->assertArrayNotHasKey('api_key', $response->json());
    }

    public function test_regenerate_key_replaces_hash_and_returns_new_raw_key(): void
    {
        $oldHash = $this->site->api_key_hash;
        $oldRawKey = 'sk_live_oldkey123456789012345678901234567890';
        $this->site->api_key_hash = Hash::make($oldRawKey);
        $this->site->save();

        $response = $this->actingAs($this->admin)->postJson("/api/admin/sites/{$this->site->id}/regenerate-key");

        $response->assertStatus(200);
        $newApiKey = $response->json('api_key');
        $this->assertIsString($newApiKey);
        $this->assertStringStartsWith('sk_live_', $newApiKey);
        $this->assertNotSame($oldRawKey, $newApiKey);

        $this->site->refresh();
        $this->assertNotSame($oldHash, $this->site->api_key_hash);
        $this->assertTrue(Hash::check($newApiKey, $this->site->api_key_hash));
        $this->assertFalse(Hash::check($oldRawKey, $this->site->api_key_hash));
    }

    public function test_site_admin_cannot_create_site_returns_403(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/sites', [
            'name' => 'New Site',
            'slug' => 'new-site',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('sites', 1);
    }

    public function test_site_admin_index_returns_only_own_site(): void
    {
        Site::create([
            'name' => 'Other Site',
            'slug' => 'other',
            'api_key_hash' => Hash::make('y'),
            'settings' => [],
            'edge_settings' => [],
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/sites');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'sites');
        $response->assertJsonPath('sites.0.id', $this->site->id);
    }

    public function test_site_admin_show_other_site_returns_404(): void
    {
        $other = Site::create([
            'name' => 'Other',
            'slug' => 'other',
            'api_key_hash' => Hash::make('y'),
            'settings' => [],
            'edge_settings' => [],
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/sites/{$other->id}");

        $response->assertStatus(404);
    }

    public function test_site_admin_cannot_regenerate_key_for_other_site(): void
    {
        $other = Site::create([
            'name' => 'Other',
            'slug' => 'other',
            'api_key_hash' => Hash::make('y'),
            'settings' => [],
            'edge_settings' => [],
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/sites/{$other->id}/regenerate-key");

        $response->assertStatus(404);
    }

    public function test_store_validates_slug_unique(): void
    {
        $this->actingAs($this->superAdmin)->postJson('/api/admin/sites', [
            'name' => 'First',
            'slug' => 'taken',
        ])->assertStatus(201);

        $response = $this->actingAs($this->superAdmin)->postJson('/api/admin/sites', [
            'name' => 'Second',
            'slug' => 'taken',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('slug');
    }

    public function test_staff_cannot_access_sites_api_returns_403(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($staff)->postJson('/api/admin/sites', [
            'name' => 'Any',
            'slug' => 'any-site',
        ]);

        $response->assertStatus(403);
    }

    public function test_super_admin_index_lists_all_sites(): void
    {
        $response = $this->actingAs($this->superAdmin)->getJson('/api/admin/sites');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'sites');
        $response->assertJsonPath('sites.0.name', 'Default Site');
        Site::create([
            'name' => 'Second',
            'slug' => 'second',
            'api_key_hash' => Hash::make('y'),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $response = $this->actingAs($this->superAdmin)->getJson('/api/admin/sites');
        $response->assertStatus(200);
        $response->assertJsonCount(2, 'sites');
    }

    public function test_update_site_edge_settings(): void
    {
        $this->site->update(['edge_settings' => ['bridge_enabled' => false, 'sync_clients' => true]]);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/sites/{$this->site->id}", [
            'edge_settings' => [
                'bridge_enabled' => true,
                'scheduled_sync_time' => '18:30',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('site.edge_settings.bridge_enabled', true);
        $response->assertJsonPath('site.edge_settings.scheduled_sync_time', '18:30');

        $this->site->refresh();
        $this->assertTrue($this->site->edge_settings['bridge_enabled']);
        $this->assertSame('18:30', $this->site->edge_settings['scheduled_sync_time']);
    }

    public function test_update_site_invalid_edge_settings_returns_422(): void
    {
        $response = $this->actingAs($this->admin)->putJson("/api/admin/sites/{$this->site->id}", [
            'edge_settings' => [
                'scheduled_sync_time' => '25:00',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['edge_settings.scheduled_sync_time']);
    }

    /** Per central-edge B.3: unknown keys in edge_settings are rejected with 422. */
    public function test_update_site_unknown_key_in_edge_settings_returns_422(): void
    {
        $response = $this->actingAs($this->admin)->putJson("/api/admin/sites/{$this->site->id}", [
            'edge_settings' => [
                'sync_clients' => true,
                'foo' => 'bar',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['edge_settings.foo']);
    }
}
