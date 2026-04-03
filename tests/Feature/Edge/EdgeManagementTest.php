<?php

namespace Tests\Feature\Edge;

use App\Models\EdgeDevice;
use App\Models\Program;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class EdgeManagementTest extends TestCase
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

    private function createDevice(Site $site, array $overrides = []): EdgeDevice
    {
        return EdgeDevice::create(array_merge([
            'site_id'              => $site->id,
            'name'                 => 'Test Device ' . uniqid(),
            'device_token_hash'    => hash('sha256', 'tok' . uniqid()),
            'id_offset'            => 10_000_000,
            'sync_mode'            => 'auto',
            'supervisor_admin_access' => false,
            'paired_at'            => now(),
        ], $overrides));
    }

    private function createAdminUser(Site $site): User
    {
        $user = User::factory()->create(['site_id' => $site->id]);
        $user->givePermissionTo('admin.manage');
        return $user;
    }

    // ── Task 1: Program model ─────────────────────────────────────────

    /** @test */
    public function program_can_be_assigned_edge_lock_via_fill(): void
    {
        $site    = $this->createSite();
        $user    = $this->createAdminUser($site);
        $program = Program::create(['site_id' => $site->id, 'name' => 'Prog', 'slug' => 'prog-' . uniqid(), 'created_by' => $user->id]);
        $device  = $this->createDevice($site);

        $program->fill(['edge_locked_by_device_id' => $device->id])->save();

        $this->assertDatabaseHas('programs', [
            'id'                       => $program->id,
            'edge_locked_by_device_id' => $device->id,
        ]);
    }

    /** @test */
    public function program_locked_by_device_relationship_returns_device(): void
    {
        $site    = $this->createSite();
        $user    = $this->createAdminUser($site);
        $program = Program::create(['site_id' => $site->id, 'name' => 'Prog2', 'slug' => 'prog2-' . uniqid(), 'created_by' => $user->id]);
        $device  = $this->createDevice($site);

        $program->update(['edge_locked_by_device_id' => $device->id]);

        $fresh = $program->fresh();
        $this->assertInstanceOf(EdgeDevice::class, $fresh->lockedByDevice);
        $this->assertEquals($device->id, $fresh->lockedByDevice->id);
    }

    // ── Task 2: EdgeDevice::getStatus() ──────────────────────────────────

    /** @test */
    public function device_status_is_online_when_seen_within_6_min_and_session_active(): void
    {
        $site   = $this->createSite();
        $device = $this->createDevice($site, [
            'last_seen_at'   => now()->subMinutes(3),
            'session_active' => true,
        ]);

        $this->assertEquals('online', $device->getStatus());
    }

    /** @test */
    public function device_status_is_waiting_when_seen_recently_and_no_program(): void
    {
        $site   = $this->createSite();
        $device = $this->createDevice($site, [
            'last_seen_at'        => now()->subMinutes(3),
            'session_active'      => false,
            'assigned_program_id' => null,
        ]);

        $this->assertEquals('waiting', $device->getStatus());
    }

    /** @test */
    public function device_status_is_idle_when_seen_recently_with_program_no_session(): void
    {
        $site    = $this->createSite();
        $program = Program::create(['site_id' => $site->id, 'name' => 'P', 'slug' => 'p-idle-' . uniqid(), 'created_by' => $this->createAdminUser($site)->id]);
        $device  = $this->createDevice($site, [
            'last_seen_at'        => now()->subMinutes(30),
            'session_active'      => false,
            'assigned_program_id' => $program->id,
        ]);

        $this->assertEquals('idle', $device->getStatus());
    }

    /** @test */
    public function device_status_is_stale_when_offline_with_assigned_program(): void
    {
        $site    = $this->createSite();
        $program = Program::create(['site_id' => $site->id, 'name' => 'P', 'slug' => 'p-stale-' . uniqid(), 'created_by' => $this->createAdminUser($site)->id]);
        $device  = $this->createDevice($site, [
            'last_seen_at'        => now()->subHours(2),
            'assigned_program_id' => $program->id,
        ]);

        $this->assertEquals('stale', $device->getStatus());
    }

    /** @test */
    public function device_status_is_offline_when_never_seen(): void
    {
        $site   = $this->createSite();
        $device = $this->createDevice($site, ['last_seen_at' => null]);

        $this->assertEquals('offline', $device->getStatus());
    }

    // ── Task 3: EdgeDeviceController ────────────────────────────────────

    /** @test */
    public function index_lists_active_devices_for_site(): void
    {
        $site  = $this->createSite(3);
        $admin = $this->createAdminUser($site);
        $dev1  = $this->createDevice($site, ['name' => 'Pi 1', 'last_seen_at' => now()->subMinutes(2), 'session_active' => true]);
        $dev2  = $this->createDevice($site, ['name' => 'Pi 2']);

        // Revoked device — should NOT appear
        $this->createDevice($site, ['revoked_at' => now()]);

        $res = $this->actingAs($admin)
            ->getJson("/api/admin/sites/{$site->id}/edge-devices");

        $res->assertOk()
            ->assertJsonCount(2, 'devices')
            ->assertJsonFragment(['name' => 'Pi 1', 'status' => 'online'])
            ->assertJsonPath('slots_used', 2)
            ->assertJsonPath('slots_total', 3);
    }

    /** @test */
    public function generate_pairing_code_returns_8_char_code(): void
    {
        $site  = $this->createSite(5);
        $admin = $this->createAdminUser($site);

        $res = $this->actingAs($admin)
            ->postJson("/api/admin/sites/{$site->id}/edge-devices/pairing-code", [
                'name' => 'New Pi',
            ]);

        $res->assertOk()
            ->assertJsonStructure(['code', 'expires_at']);

        $this->assertSame(8, strlen($res->json('code')));
    }

    /** @test */
    public function generate_pairing_code_rejected_when_limit_reached(): void
    {
        $site  = $this->createSite(1);
        $admin = $this->createAdminUser($site);
        $this->createDevice($site); // fills the 1 slot

        $res = $this->actingAs($admin)
            ->postJson("/api/admin/sites/{$site->id}/edge-devices/pairing-code", [
                'name' => 'Extra Pi',
            ]);

        $res->assertStatus(422);
    }

    /** @test */
    public function update_assigns_program_to_device_and_locks_it(): void
    {
        $site    = $this->createSite();
        $admin   = $this->createAdminUser($site);
        $device  = $this->createDevice($site);
        $program = Program::create(['site_id' => $site->id, 'name' => 'Enrol', 'slug' => 'enrol-' . uniqid(), 'created_by' => $admin->id]);

        $res = $this->actingAs($admin)
            ->putJson("/api/admin/edge-devices/{$device->id}", [
                'assigned_program_id'     => $program->id,
                'sync_mode'               => 'auto',
                'supervisor_admin_access' => false,
            ]);

        $res->assertOk()->assertJsonPath('device.assigned_program_id', $program->id);

        $this->assertDatabaseHas('programs', [
            'id'                       => $program->id,
            'edge_locked_by_device_id' => $device->id,
        ]);
    }

    /** @test */
    public function update_clears_old_lock_when_reassigning_program(): void
    {
        $site   = $this->createSite();
        $admin  = $this->createAdminUser($site);
        $device = $this->createDevice($site);
        $prog1  = Program::create(['site_id' => $site->id, 'name' => 'P1', 'slug' => 'p1-' . uniqid(), 'created_by' => $admin->id]);
        $prog2  = Program::create(['site_id' => $site->id, 'name' => 'P2', 'slug' => 'p2-' . uniqid(), 'created_by' => $admin->id]);

        // Assign prog1
        $device->update(['assigned_program_id' => $prog1->id]);
        $prog1->update(['edge_locked_by_device_id' => $device->id]);

        // Reassign to prog2
        $this->actingAs($admin)
            ->putJson("/api/admin/edge-devices/{$device->id}", [
                'assigned_program_id'     => $prog2->id,
                'sync_mode'               => 'auto',
                'supervisor_admin_access' => false,
            ])
            ->assertOk();

        $this->assertDatabaseHas('programs', ['id' => $prog1->id, 'edge_locked_by_device_id' => null]);
        $this->assertDatabaseHas('programs', ['id' => $prog2->id, 'edge_locked_by_device_id' => $device->id]);
    }

    /** @test */
    public function update_cannot_assign_program_already_locked_by_another_device(): void
    {
        $site    = $this->createSite();
        $admin   = $this->createAdminUser($site);
        $dev1    = $this->createDevice($site, ['name' => 'Dev 1']);
        $dev2    = $this->createDevice($site, ['name' => 'Dev 2']);
        $program = Program::create(['site_id' => $site->id, 'name' => 'P', 'slug' => 'plock-' . uniqid(), 'created_by' => $admin->id]);

        $dev1->update(['assigned_program_id' => $program->id]);
        $program->update(['edge_locked_by_device_id' => $dev1->id]);

        $res = $this->actingAs($admin)
            ->putJson("/api/admin/edge-devices/{$dev2->id}", [
                'assigned_program_id'     => $program->id,
                'sync_mode'               => 'auto',
                'supervisor_admin_access' => false,
            ]);

        $res->assertStatus(422);
    }

    /** @test */
    public function revoke_sets_revoked_at_and_releases_program_lock(): void
    {
        $site    = $this->createSite();
        $admin   = $this->createAdminUser($site);
        $device  = $this->createDevice($site);
        $program = Program::create(['site_id' => $site->id, 'name' => 'P', 'slug' => 'prev-' . uniqid(), 'created_by' => $admin->id]);

        $device->update(['assigned_program_id' => $program->id]);
        $program->update(['edge_locked_by_device_id' => $device->id]);

        $res = $this->actingAs($admin)
            ->deleteJson("/api/admin/edge-devices/{$device->id}");

        $res->assertOk()->assertJsonPath('message', 'Device revoked.');

        $this->assertDatabaseMissing('programs', ['edge_locked_by_device_id' => $device->id]);
        $this->assertNotNull(EdgeDevice::find($device->id)->revoked_at);
    }

    /** @test */
    public function admin_cannot_manage_devices_from_another_site(): void
    {
        $site1 = $this->createSite();
        $site2 = $this->createSite();
        $admin = $this->createAdminUser($site1);
        $dev   = $this->createDevice($site2);

        $this->actingAs($admin)
            ->getJson("/api/admin/sites/{$site2->id}/edge-devices")
            ->assertForbidden();

        $this->actingAs($admin)
            ->putJson("/api/admin/edge-devices/{$dev->id}", [
                'assigned_program_id'     => null,
                'sync_mode'               => 'auto',
                'supervisor_admin_access' => false,
            ])
            ->assertForbidden();
    }
}
