<?php

namespace Tests\Feature\Edge;

use App\Models\EdgeDevice;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeartbeatRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private function makeSite(): Site
    {
        return Site::factory()->create(['settings' => ['max_edge_devices' => 5]]);
    }

    private function makeDevice(Site $site, array $overrides = []): EdgeDevice
    {
        $plainToken = 'test-token-' . uniqid();

        return EdgeDevice::create(array_merge([
            'site_id' => $site->id,
            'name' => 'Test Device',
            'device_token_hash' => hash('sha256', $plainToken),
            'id_offset' => 10_000_000,
            'sync_mode' => 'auto',
            'supervisor_admin_access' => false,
            'assigned_program_id' => null,
            'session_active' => false,
            'paired_at' => now(),
            '_plain_token' => $plainToken,
        ], $overrides));
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

    public function test_heartbeat_persists_runtime_phone(): void
    {
        $site = $this->makeSite();
        $device = $this->makeDevice($site);

        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload(['runtime' => 'phone']))
            ->assertOk();

        $this->assertSame('phone', $device->fresh()->runtime);
    }

    public function test_heartbeat_persists_runtime_pi(): void
    {
        $site = $this->makeSite();
        $device = $this->makeDevice($site);

        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload(['runtime' => 'pi']))
            ->assertOk();

        $this->assertSame('pi', $device->fresh()->runtime);
    }

    public function test_heartbeat_persists_runtime_dev(): void
    {
        $site = $this->makeSite();
        $device = $this->makeDevice($site);

        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload(['runtime' => 'dev']))
            ->assertOk();

        $this->assertSame('dev', $device->fresh()->runtime);
    }

    public function test_heartbeat_does_not_overwrite_runtime_when_not_sent(): void
    {
        $site = $this->makeSite();
        $device = $this->makeDevice($site, ['runtime' => 'phone']);

        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload())
            ->assertOk();

        $this->assertSame('phone', $device->fresh()->runtime);
    }

    public function test_heartbeat_rejects_invalid_runtime(): void
    {
        $site = $this->makeSite();
        $device = $this->makeDevice($site);

        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload(['runtime' => 'laptop']))
            ->assertUnprocessable();
    }

    public function test_heartbeat_updates_runtime_from_dev_to_phone(): void
    {
        $site = $this->makeSite();
        $device = $this->makeDevice($site, ['runtime' => 'dev']);

        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload(['runtime' => 'phone']))
            ->assertOk();

        $this->assertSame('phone', $device->fresh()->runtime);
    }
}