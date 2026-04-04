<?php

namespace Tests\Feature\Edge;

use App\Models\EdgeDevice;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EdgeAppVersionTest extends TestCase
{
    use RefreshDatabase;

    private function makeSite(): Site
    {
        return Site::factory()->create(['settings' => ['max_edge_devices' => 5]]);
    }

    private function makeDevice(Site $site): EdgeDevice
    {
        $plainToken = 'test-token-' . uniqid();

        return EdgeDevice::create([
            'site_id' => $site->id,
            'name' => 'Test Pi',
            'device_token_hash' => hash('sha256', $plainToken),
            'id_offset' => 10_000_000,
            'sync_mode' => 'auto',
            'supervisor_admin_access' => false,
            'assigned_program_id' => null,
            'session_active' => false,
            'paired_at' => now(),
            '_plain_token' => $plainToken,
        ]);
    }

    private function heartbeatPayload(array $overrides = []): array
    {
        return array_merge([
            'session_active' => false,
            'sync_mode' => 'auto',
            'last_synced_at' => null,
            'package_version' => null,
            'app_version' => null,
        ], $overrides);
    }

    // ── E8.1: HeartbeatController update_available ───────────────────────

    public function test_heartbeat_returns_update_available_true_when_device_version_is_behind(): void
    {
        config(['flexiqueue.latest_edge_app_version' => '1.1.0']);
        $site = $this->makeSite();
        $device = $this->makeDevice($site);
        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload(['app_version' => '1.0.0']))
            ->assertOk()
            ->assertJsonFragment(['update_available' => true]);
    }

    public function test_heartbeat_returns_update_available_false_when_version_is_current(): void
    {
        config(['flexiqueue.latest_edge_app_version' => '1.1.0']);
        $site = $this->makeSite();
        $device = $this->makeDevice($site);
        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload(['app_version' => '1.1.0']))
            ->assertOk()
            ->assertJsonFragment(['update_available' => false]);
    }

    public function test_heartbeat_returns_update_available_false_when_app_version_not_sent(): void
    {
        config(['flexiqueue.latest_edge_app_version' => '1.1.0']);
        $site = $this->makeSite();
        $device = $this->makeDevice($site);
        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload(['app_version' => null]))
            ->assertOk()
            ->assertJsonFragment(['update_available' => false]);
    }

    public function test_heartbeat_returns_update_available_false_when_latest_version_not_configured(): void
    {
        config(['flexiqueue.latest_edge_app_version' => null]);
        $site = $this->makeSite();
        $device = $this->makeDevice($site);
        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload(['app_version' => '1.0.0']))
            ->assertOk()
            ->assertJsonFragment(['update_available' => false]);
    }

    public function test_heartbeat_sets_update_status_to_update_available_when_behind(): void
    {
        config(['flexiqueue.latest_edge_app_version' => '1.1.0']);
        $site = $this->makeSite();
        $device = $this->makeDevice($site);
        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload(['app_version' => '1.0.0']))
            ->assertOk();
        $this->assertSame('update_available', $device->fresh()->update_status);
    }

    public function test_heartbeat_sets_update_status_to_up_to_date_when_current(): void
    {
        config(['flexiqueue.latest_edge_app_version' => '1.1.0']);
        $site = $this->makeSite();
        $device = $this->makeDevice($site);
        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload(['app_version' => '1.1.0']))
            ->assertOk();
        $this->assertSame('up_to_date', $device->fresh()->update_status);
    }

    // ── E8.2: EdgeDeviceState column ─────────────────────────────────

    public function test_edge_device_state_has_update_available_column(): void
    {
        \App\Models\EdgeDeviceState::where('id', 1)->updateOrInsert(
            ['id' => 1],
            ['sync_mode' => 'auto', 'session_active' => false]
        );
        \App\Models\EdgeDeviceState::where('id', 1)->update(['update_available' => true]);
        $this->assertTrue(
            (bool) \App\Models\EdgeDeviceState::find(1)?->update_available
        );
    }

    // ── E8.3: EdgeHeartbeat command ───────────────────────────────────

    public function test_heartbeat_command_stores_update_available_true_in_device_state(): void
    {
        // device_token is encrypted in the DB; use Crypt::encrypt for test values
        \App\Models\EdgeDeviceState::where('id', 1)->updateOrInsert(
            ['id' => 1],
            [
                'paired_at' => now(),
                'device_token' => Crypt::encrypt('test-token'),
                'central_url' => 'http://central.test',
                'session_active' => false,
                'sync_mode' => 'auto',
            ]
        );

        Http::fake([
            'http://central.test/api/edge/heartbeat' => Http::response([
                'revoked' => false,
                'update_available' => true,
                'package_stale' => false,
                'sync_mode' => 'auto',
            ], 200),
        ]);

        config(['app.mode' => 'edge']);
        config(['app.central_api_key' => 'test-api-key']);
        $this->artisan('edge:heartbeat')->assertSuccessful();

        $this->assertTrue(
            (bool) \App\Models\EdgeDeviceState::current()->update_available
        );
    }

    public function test_heartbeat_command_stores_update_available_false_in_device_state(): void
    {
        \App\Models\EdgeDeviceState::where('id', 1)->updateOrInsert(
            ['id' => 1],
            [
                'paired_at' => now(),
                'device_token' => Crypt::encrypt('test-token'),
                'central_url' => 'http://central.test',
                'session_active' => false,
                'sync_mode' => 'auto',
                'update_available' => true,
            ]
        );

        Http::fake([
            'http://central.test/api/edge/heartbeat' => Http::response([
                'revoked' => false,
                'update_available' => false,
                'package_stale' => false,
                'sync_mode' => 'auto',
            ], 200),
        ]);

        config(['app.mode' => 'edge']);
        config(['app.central_api_key' => 'test-api-key']);
        $this->artisan('edge:heartbeat')->assertSuccessful();

        $this->assertFalse(
            (bool) \App\Models\EdgeDeviceState::current()->update_available
        );
    }

    // ── E8.3: EdgeImportController status endpoint ────────────────────

    public function test_import_status_endpoint_returns_update_available(): void
    {
        config(['app.mode' => 'edge']);

        \App\Models\EdgeDeviceState::where('id', 1)->updateOrInsert(
            ['id' => 1],
            [
                'sync_mode' => 'auto',
                'session_active' => false,
                'package_stale' => false,
                'update_available' => true,
            ]
        );

        Storage::disk('local')->put(
            'edge_package_imported.json',
            json_encode([
                'status' => 'complete',
                'imported_at' => now()->toIso8601String(),
                'program_id' => 1,
                'site_id' => 1,
                'manifest_hash' => str_repeat('a', 64),
                'package_version' => str_repeat('b', 64),
                'sync_tokens' => false,
                'sync_clients' => false,
                'sync_tts' => false,
                'tts_asset_contract_version' => 2,
                'tts_asset_references_count' => 0,
            ])
        );

        $user = User::factory()->admin()->create();
        $this->actingAs($user)
            ->getJson('/api/admin/edge/import/status')
            ->assertOk()
            ->assertJsonFragment(['update_available' => true]);
    }

    // ── E8.4: Admin device list ─────────────────────────────────────

    public function test_device_list_includes_app_version_and_update_status(): void
    {
        $site = $this->makeSite();
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);
        $this->actingAs($admin);

        $plainToken = 'test-token-' . uniqid();
        EdgeDevice::create([
            'site_id' => $site->id,
            'name' => 'Pi-List',
            'device_token_hash' => hash('sha256', $plainToken),
            'id_offset' => 10_000_000,
            'sync_mode' => 'auto',
            'supervisor_admin_access' => false,
            'assigned_program_id' => null,
            'session_active' => false,
            'paired_at' => now(),
            'app_version' => '1.0.0',
            'update_status' => 'update_available',
            '_plain_token' => $plainToken,
        ]);

        $this->getJson("/api/admin/sites/{$site->id}/edge-devices")
            ->assertOk()
            ->assertJsonFragment([
                'app_version' => '1.0.0',
                'update_status' => 'update_available',
            ]);
    }
}
