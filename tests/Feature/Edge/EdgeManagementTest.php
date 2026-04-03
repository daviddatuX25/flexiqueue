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
}
