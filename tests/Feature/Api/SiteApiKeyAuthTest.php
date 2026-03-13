<?php

namespace Tests\Feature\Api;

use App\Models\Site;
use App\Services\SiteApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Per central-edge B.2: Site API key auth middleware (sync/bridge stub).
 */
class SiteApiKeyAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_with_missing_authorization_header_returns_401(): void
    {
        $response = $this->postJson('/api/sync/test-site-auth');

        $response->assertStatus(401);
        $response->assertJsonFragment(['message' => 'Missing or invalid Authorization header.']);
    }

    public function test_request_with_malformed_authorization_header_returns_401(): void
    {
        $response = $this->postJson('/api/sync/test-site-auth', [], [
            'Authorization' => 'Basic abc123',
        ]);

        $response->assertStatus(401);
        $response->assertJsonFragment(['message' => 'Authorization must use Bearer scheme.']);
    }

    public function test_request_with_empty_bearer_token_returns_401(): void
    {
        $response = $this->postJson('/api/sync/test-site-auth', [], [
            'Authorization' => 'Bearer ',
        ]);

        $response->assertStatus(401);
    }

    public function test_request_with_invalid_api_key_returns_401(): void
    {
        Site::create([
            'name' => 'A',
            'slug' => 'site-a',
            'api_key_hash' => Hash::make('sk_live_valid'),
            'settings' => [],
            'edge_settings' => [],
        ]);

        $response = $this->postJson('/api/sync/test-site-auth', [], [
            'Authorization' => 'Bearer invalidkey',
        ]);

        $response->assertStatus(401);
        $response->assertJsonFragment(['message' => 'Invalid or revoked API key.']);
    }

    public function test_request_with_valid_api_key_returns_200_and_is_site_scoped(): void
    {
        $siteApiKeyService = app(SiteApiKeyService::class);
        $site1 = Site::create([
            'name' => 'Site One',
            'slug' => 'site-one',
            'api_key_hash' => Hash::make('placeholder'),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $key1 = $siteApiKeyService->assignNewKey($site1);

        $site2 = Site::create([
            'name' => 'Site Two',
            'slug' => 'site-two',
            'api_key_hash' => Hash::make('placeholder2'),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $key2 = $siteApiKeyService->assignNewKey($site2);

        $response1 = $this->postJson('/api/sync/test-site-auth', [], [
            'Authorization' => 'Bearer ' . $key1,
        ]);
        $response1->assertStatus(200);
        $response1->assertJsonPath('site_id', $site1->id);
        $response1->assertJsonPath('slug', 'site-one');

        $response2 = $this->postJson('/api/sync/test-site-auth', [], [
            'Authorization' => 'Bearer ' . $key2,
        ]);
        $response2->assertStatus(200);
        $response2->assertJsonPath('site_id', $site2->id);
        $response2->assertJsonPath('slug', 'site-two');
    }

    public function test_old_key_fails_and_new_key_succeeds_after_regeneration(): void
    {
        $siteApiKeyService = app(SiteApiKeyService::class);
        $site = Site::create([
            'name' => 'Regen',
            'slug' => 'regen',
            'api_key_hash' => Hash::make('x'),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $oldKey = $siteApiKeyService->assignNewKey($site);

        $admin = \App\Models\User::factory()->admin()->create(['site_id' => $site->id]);
        $regenerateResponse = $this->actingAs($admin)->postJson("/api/admin/sites/{$site->id}/regenerate-key");
        $regenerateResponse->assertStatus(200);
        $newKey = $regenerateResponse->json('api_key');
        $this->assertNotSame($oldKey, $newKey);

        $oldKeyResponse = $this->postJson('/api/sync/test-site-auth', [], [
            'Authorization' => 'Bearer ' . $oldKey,
        ]);
        $oldKeyResponse->assertStatus(401);

        $newKeyResponse = $this->postJson('/api/sync/test-site-auth', [], [
            'Authorization' => 'Bearer ' . $newKey,
        ]);
        $newKeyResponse->assertStatus(200);
        $newKeyResponse->assertJsonPath('site_id', $site->id);
        $newKeyResponse->assertJsonPath('slug', 'regen');
    }
}
