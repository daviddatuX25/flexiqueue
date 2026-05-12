<?php

namespace Tests\Feature\Edge;

use App\Models\EdgeDevice;
use App\Models\EdgePairingCode;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class EdgeApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ──────────────────────────────────────────────────────────

    private function createSite(int $maxDevices = 5): Site
    {
        return Site::create([
            'name'          => 'Test Site ' . uniqid(),
            'slug'          => 'test-site-' . uniqid(),
            'api_key_hash'  => Hash::make(Str::random(40)),
            'edge_settings' => ['max_edge_devices' => $maxDevices],
        ]);
    }

    private function createAdminUser(Site $site): User
    {
        $user = User::factory()->create(['site_id' => $site->id]);
        $user->givePermissionTo('admin.manage');
        return $user;
    }

    private function createPendingDevice(Site $site, array $overrides = []): EdgeDevice
    {
        return EdgeDevice::create(array_merge([
            'site_id'                 => $site->id,
            'name'                    => 'Pending Device ' . uniqid(),
            'device_token_hash'       => null,
            'id_offset'               => 10_000_000,
            'sync_mode'               => 'auto',
            'supervisor_admin_access' => false,
            'paired_at'               => null,
            'approval_status'         => 'pending',
        ], $overrides));
    }

    // ── Pairing returns pending_approval ─────────────────────────────

    /** @test */
    public function pairing_creates_device_in_pending_state(): void
    {
        $site = $this->createSite();
        $user = $this->createAdminUser($site);

        // Generate a pairing code via admin API
        $response = $this->actingAs($user)
            ->postJson("/api/admin/sites/{$site->id}/edge-devices/pairing-code", [
                'name' => 'Test Edge Device',
            ]);

        $response->assertOk();
        $code = $response->json('code');

        // Consume the pairing code via edge pair endpoint
        $response = $this->postJson('/api/edge/pair', [
            'pairing_code' => $code,
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'pending_approval']);
        $response->assertJsonMissing(['device_token']);

        // Device should exist with pending approval
        $device = EdgeDevice::where('site_id', $site->id)->first();
        $this->assertNotNull($device);
        $this->assertEquals('pending', $device->approval_status);
        $this->assertNull($device->paired_at);
        $this->assertNull($device->device_token_hash);
    }

    // ── Admin approve ────────────────────────────────────────────

    /** @test */
    public function admin_can_approve_pending_device(): void
    {
        $site = $this->createSite();
        $user = $this->createAdminUser($site);
        $device = $this->createPendingDevice($site);

        $response = $this->actingAs($user)
            ->postJson("/api/admin/edge-devices/{$device->id}/approve");

        $response->assertOk();
        $response->assertJson(['message' => 'Device approved.']);

        $device->refresh();
        $this->assertEquals('approved', $device->approval_status);
        $this->assertNotNull($device->approved_at);
        $this->assertEquals($user->id, $device->approved_by);
        $this->assertNotNull($device->paired_at);
        $this->assertNotEmpty($device->device_token_hash);

        // Token should be returned in response
        $this->assertNotEmpty($response->json('device_token'));
    }

    /** @test */
    public function approve_rejects_already_approved_device(): void
    {
        $site = $this->createSite();
        $user = $this->createAdminUser($site);
        $device = $this->createPendingDevice($site);

        // Approve it first
        $this->actingAs($user)
            ->postJson("/api/admin/edge-devices/{$device->id}/approve");

        // Try to approve again
        $response = $this->actingAs($user)
            ->postJson("/api/admin/edge-devices/{$device->id}/approve");

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Device is not pending approval.']);
    }

    // ── Admin reject ─────────────────────────────────────────────

    /** @test */
    public function admin_can_reject_pending_device(): void
    {
        $site = $this->createSite();
        $user = $this->createAdminUser($site);
        $device = $this->createPendingDevice($site);

        $response = $this->actingAs($user)
            ->postJson("/api/admin/edge-devices/{$device->id}/reject");

        $response->assertOk();
        $response->assertJson(['message' => 'Device rejected.']);

        $device->refresh();
        $this->assertEquals('rejected', $device->approval_status);
        $this->assertNotNull($device->rejected_at);
        // Should NOT have paired_at or token
        $this->assertNull($device->paired_at);
    }

    /** @test */
    public function reject_rejects_already_approved_device(): void
    {
        $site = $this->createSite();
        $user = $this->createAdminUser($site);
        $device = $this->createPendingDevice($site);

        // Approve it first
        $this->actingAs($user)
            ->postJson("/api/admin/edge-devices/{$device->id}/approve");

        // Try to reject an already-approved device
        $response = $this->actingAs($user)
            ->postJson("/api/admin/edge-devices/{$device->id}/reject");

        $response->assertStatus(422);
    }

    // ── EdgeDeviceResource includes approval_status ──────────────

    /** @test */
    public function device_resource_includes_approval_status(): void
    {
        $site = $this->createSite();
        $user = $this->createAdminUser($site);
        $device = $this->createPendingDevice($site);

        $response = $this->actingAs($user)
            ->getJson("/api/admin/sites/{$site->id}/edge-devices");

        $response->assertOk();
        $devices = $response->json('devices');
        $found = collect($devices)->first(fn ($d) => $d['id'] === $device->id);
        $this->assertNotNull($found);
        $this->assertEquals('pending', $found['approval_status']);
    }

    // ── EdgeDevice model helpers ──────────────────────────────────

    /** @test */
    public function isPendingApproval_returns_true_for_pending_device(): void
    {
        $site = $this->createSite();
        $device = $this->createPendingDevice($site);

        $this->assertTrue($device->isPendingApproval());
        $this->assertFalse($device->isApproved());
    }

    /** @test */
    public function isApproved_returns_true_for_approved_device(): void
    {
        $site = $this->createSite();
        $device = EdgeDevice::create([
            'site_id'                 => $site->id,
            'name'                    => 'Approved Device',
            'device_token_hash'       => hash('sha256', Str::random(64)),
            'id_offset'               => 10_000_000,
            'sync_mode'               => 'auto',
            'supervisor_admin_access' => false,
            'paired_at'               => now(),
            'approval_status'         => 'approved',
        ]);

        $this->assertTrue($device->isApproved());
        $this->assertFalse($device->isPendingApproval());
    }

    // ── Approval status defaults to 'approved' for backward compat ──

    /** @test */
    public function existing_devices_default_to_approved_status(): void
    {
        $site = $this->createSite();
        $device = EdgeDevice::create([
            'site_id'                 => $site->id,
            'name'                    => 'Legacy Device',
            'device_token_hash'       => hash('sha256', Str::random(64)),
            'id_offset'               => 10_000_000,
            'sync_mode'               => 'auto',
            'supervisor_admin_access' => false,
            'paired_at'               => now(),
            // Explicitly set approval_status since SQLite doesn't apply DB defaults on insert
            'approval_status'         => 'approved',
        ]);

        $this->assertEquals('approved', $device->approval_status);
        $this->assertTrue($device->isApproved());
    }

    // ── EdgeBootGuard redirects pending devices ──────────────────

    /** @test */
    public function edge_boot_guard_redirects_pending_device_to_awaiting_approval(): void
    {
        $site = $this->createSite();

        \App\Models\EdgeDeviceState::updateOrCreate(
            ['id' => 1],
            [
                'paired_at'               => null,
                'approval_status'         => 'pending',
                'central_url'             => 'https://example.com',
                'site_id'                 => $site->id,
                'site_name'               => 'Test Site',
                'sync_mode'               => 'auto',
                'supervisor_admin_access' => false,
                'session_active'          => false,
                'is_revoked'              => false,
            ]
        );

        // Set edge mode
        config(['app.mode' => 'edge']);

        $response = $this->get('/edge/waiting');

        $response->assertRedirect('/edge/awaiting-approval');
    }

    /** @test */
    public function edge_approval_status_endpoint_returns_pending(): void
    {
        $site = $this->createSite();

        \App\Models\EdgeDeviceState::updateOrCreate(
            ['id' => 1],
            [
                'paired_at'               => null,
                'approval_status'         => 'pending',
                'central_url'             => 'https://example.com',
                'site_id'                 => $site->id,
                'site_name'               => 'Test Site',
                'sync_mode'               => 'auto',
                'supervisor_admin_access' => false,
                'session_active'          => false,
                'is_revoked'              => false,
            ]
        );

        $response = $this->getJson('/edge/approval-status');

        $response->assertOk();
        $response->assertJson(['status' => 'pending', 'paired' => false]);
    }

    /** @test */
    public function edge_approval_status_endpoint_returns_approved_when_paired(): void
    {
        $site = $this->createSite();

        \App\Models\EdgeDeviceState::updateOrCreate(
            ['id' => 1],
            [
                'paired_at'               => now(),
                'approval_status'         => 'approved',
                'central_url'             => 'https://example.com',
                'site_id'                 => $site->id,
                'site_name'               => 'Test Site',
                'sync_mode'               => 'auto',
                'supervisor_admin_access' => false,
                'session_active'          => false,
                'is_revoked'              => false,
            ]
        );

        $response = $this->getJson('/edge/approval-status');

        $response->assertOk();
        $response->assertJson(['status' => 'approved', 'paired' => true]);
    }
}