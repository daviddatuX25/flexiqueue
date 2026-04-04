<?php

namespace Tests\Feature\Edge;

use App\Models\EdgeDevice;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'site_id'                 => $site->id,
            'name'                    => 'Test Pi',
            'device_token_hash'       => hash('sha256', $plainToken),
            'id_offset'               => 10_000_000,
            'sync_mode'               => 'auto',
            'supervisor_admin_access' => false,
            'assigned_program_id'     => null,
            'session_active'          => false,
            'paired_at'               => now(),
            '_plain_token'            => $plainToken,
        ]);
    }

    private function heartbeatPayload(array $overrides = []): array
    {
        return array_merge([
            'session_active'  => false,
            'sync_mode'       => 'auto',
            'last_synced_at'  => null,
            'package_version' => null,
            'app_version'     => null,
        ], $overrides);
    }

    /** @test */
    public function heartbeat_returns_update_available_true_when_device_version_is_behind(): void
    {
        config(['flexiqueue.latest_edge_app_version' => '1.1.0']);
        $site   = $this->makeSite();
        $device = $this->makeDevice($site);
        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload(['app_version' => '1.0.0']))
            ->assertOk()
            ->assertJsonFragment(['update_available' => true]);
    }

    /** @test */
    public function heartbeat_returns_update_available_false_when_version_is_current(): void
    {
        config(['flexiqueue.latest_edge_app_version' => '1.1.0']);
        $site   = $this->makeSite();
        $device = $this->makeDevice($site);
        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload(['app_version' => '1.1.0']))
            ->assertOk()
            ->assertJsonFragment(['update_available' => false]);
    }

    /** @test */
    public function heartbeat_returns_update_available_false_when_app_version_not_sent(): void
    {
        config(['flexiqueue.latest_edge_app_version' => '1.1.0']);
        $site   = $this->makeSite();
        $device = $this->makeDevice($site);
        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload(['app_version' => null]))
            ->assertOk()
            ->assertJsonFragment(['update_available' => false]);
    }

    /** @test */
    public function heartbeat_returns_update_available_false_when_latest_version_not_configured(): void
    {
        config(['flexiqueue.latest_edge_app_version' => null]);
        $site   = $this->makeSite();
        $device = $this->makeDevice($site);
        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload(['app_version' => '1.0.0']))
            ->assertOk()
            ->assertJsonFragment(['update_available' => false]);
    }

    /** @test */
    public function heartbeat_sets_update_status_to_update_available_when_behind(): void
    {
        config(['flexiqueue.latest_edge_app_version' => '1.1.0']);
        $site   = $this->makeSite();
        $device = $this->makeDevice($site);
        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload(['app_version' => '1.0.0']))
            ->assertOk();
        $this->assertSame('update_available', $device->fresh()->update_status);
    }

    /** @test */
    public function heartbeat_sets_update_status_to_up_to_date_when_current(): void
    {
        config(['flexiqueue.latest_edge_app_version' => '1.1.0']);
        $site   = $this->makeSite();
        $device = $this->makeDevice($site);
        $this->withToken($device->_plain_token)
            ->postJson('/api/edge/heartbeat', $this->heartbeatPayload(['app_version' => '1.1.0']))
            ->assertOk();
        $this->assertSame('up_to_date', $device->fresh()->update_status);
    }
}
