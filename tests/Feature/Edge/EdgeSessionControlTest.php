<?php

namespace Tests\Feature\Edge;

use App\Models\EdgeDevice;
use App\Models\Program;
use App\Models\Site;
use App\Models\User;
use App\Services\ProgramLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EdgeSessionControlTest extends TestCase
{
    use RefreshDatabase;

    private function makeSite(): Site
    {
        return Site::factory()->create(['settings' => ['max_edge_devices' => 5]]);
    }

    private function makeProgram(Site $site): Program
    {
        return Program::factory()->create(['site_id' => $site->id]);
    }

    private function makeAdmin(Site $site): User
    {
        return User::factory()->admin()->create(['site_id' => $site->id]);
    }

    private function makeDevice(Site $site, ?Program $program = null): EdgeDevice
    {
        $plainToken = 'test-token-' . uniqid();
        return EdgeDevice::create([
            'site_id'            => $site->id,
            'name'               => 'Test Pi',
            'device_token_hash'  => hash('sha256', $plainToken),
            'id_offset'          => 10_000_000,
            'sync_mode'          => 'auto',
            'supervisor_admin_access' => false,
            'assigned_program_id'    => $program?->id,
            'session_active'     => false,
            'paired_at'          => now(),
            '_plain_token'       => $plainToken,
        ]);
    }

    private function tokenFor(EdgeDevice $device): string
    {
        return $device->_plain_token ?? 'unknown';
    }

    /** @test */
    public function lock_sets_edge_locked_by_device_id_on_program(): void
    {
        $site    = $this->makeSite();
        $device  = $this->makeDevice($site);
        $program = $this->makeProgram($site);

        app(ProgramLockService::class)->lock($device, $program);

        $this->assertDatabaseHas('programs', [
            'id'                       => $program->id,
            'edge_locked_by_device_id' => $device->id,
        ]);
    }

    /** @test */
    public function unlock_clears_edge_locked_by_device_id(): void
    {
        $site    = $this->makeSite();
        $device  = $this->makeDevice($site);
        $program = $this->makeProgram($site);
        $program->update(['edge_locked_by_device_id' => $device->id]);

        app(ProgramLockService::class)->unlock($program);

        $this->assertDatabaseHas('programs', [
            'id'                       => $program->id,
            'edge_locked_by_device_id' => null,
        ]);
    }

    /** @test */
    public function is_locked_by_other_returns_true_when_different_device_holds_lock(): void
    {
        $site     = $this->makeSite();
        $deviceA  = $this->makeDevice($site);
        $deviceB  = $this->makeDevice($site);
        $program  = $this->makeProgram($site);
        $program->update(['edge_locked_by_device_id' => $deviceA->id]);

        $this->assertTrue(
            app(ProgramLockService::class)->isLockedByOtherDevice($program, $deviceB)
        );
    }

    /** @test */
    public function is_locked_by_other_returns_false_for_same_device(): void
    {
        $site    = $this->makeSite();
        $device  = $this->makeDevice($site);
        $program = $this->makeProgram($site);
        $program->update(['edge_locked_by_device_id' => $device->id]);

        $this->assertFalse(
            app(ProgramLockService::class)->isLockedByOtherDevice($program, $device)
        );
    }

    // ── E4.2 Block central session start ─────────────────────────────────

    /** @test */
    public function activate_throws_when_program_is_edge_locked(): void
    {
        $site    = $this->makeSite();
        $device  = $this->makeDevice($site);
        $program = $this->makeProgram($site);
        $program->update(['edge_locked_by_device_id' => $device->id]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/edge device/i');

        app(\App\Services\ProgramService::class)->activate($program);
    }

    /** @test */
    public function activate_exclusive_throws_when_program_is_edge_locked(): void
    {
        $site    = $this->makeSite();
        $device  = $this->makeDevice($site);
        $program = $this->makeProgram($site);
        $program->update(['edge_locked_by_device_id' => $device->id]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/edge device/i');

        app(\App\Services\ProgramService::class)->activateExclusive($program);
    }

    /** @test */
    public function activate_succeeds_when_program_is_not_edge_locked(): void
    {
        $site    = $this->makeSite();
        $program = $this->makeProgram($site);

        // Should not throw
        $result = app(\App\Services\ProgramService::class)->activate($program->fresh());

        $this->assertTrue($result->is_active);
    }

    // ── E4.3 POST /api/edge/session/start ────────────────────────────────

    /** @test */
    public function session_start_sets_session_active_on_device(): void
    {
        $site    = $this->makeSite();
        $program = $this->makeProgram($site);
        $plainToken = 'tok-' . uniqid();
        $device  = EdgeDevice::create([
            'site_id'             => $site->id,
            'name'                => 'Test Pi',
            'device_token_hash'   => hash('sha256', $plainToken),
            'id_offset'           => 10_000_000,
            'sync_mode'           => 'auto',
            'supervisor_admin_access' => false,
            'assigned_program_id' => $program->id,
            'session_active'      => false,
            'paired_at'           => now(),
        ]);

        $response = $this->withToken($plainToken)
            ->postJson('/api/edge/session/start');

        $response->assertOk()->assertJson(['session_active' => true]);
        $this->assertDatabaseHas('edge_devices', [
            'id'             => $device->id,
            'session_active' => true,
        ]);
    }

    /** @test */
    public function session_start_returns_422_when_no_program_assigned(): void
    {
        $site       = $this->makeSite();
        $plainToken = 'tok-' . uniqid();
        EdgeDevice::create([
            'site_id'             => $site->id,
            'name'                => 'Test Pi',
            'device_token_hash'   => hash('sha256', $plainToken),
            'id_offset'           => 10_000_000,
            'sync_mode'           => 'auto',
            'supervisor_admin_access' => false,
            'assigned_program_id' => null,
            'session_active'      => false,
            'paired_at'           => now(),
        ]);

        $this->withToken($plainToken)
            ->postJson('/api/edge/session/start')
            ->assertUnprocessable();
    }

    // ── E4.4 POST /api/edge/session/end ──────────────────────────────────

    /** @test */
    public function session_end_clears_session_active_and_releases_lock(): void
    {
        $site    = $this->makeSite();
        $program = $this->makeProgram($site);
        $plainToken = 'tok-' . uniqid();
        $device  = EdgeDevice::create([
            'site_id'             => $site->id,
            'name'                => 'Test Pi',
            'device_token_hash'   => hash('sha256', $plainToken),
            'id_offset'           => 10_000_000,
            'sync_mode'           => 'auto',
            'supervisor_admin_access' => false,
            'assigned_program_id' => $program->id,
            'session_active'      => true,
            'paired_at'           => now(),
        ]);
        $program->update(['edge_locked_by_device_id' => $device->id]);

        $this->withToken($plainToken)
            ->postJson('/api/edge/session/end')
            ->assertOk()
            ->assertJson(['session_active' => false]);

        $this->assertDatabaseHas('edge_devices', [
            'id'             => $device->id,
            'session_active' => false,
        ]);
        $this->assertDatabaseHas('programs', [
            'id'                       => $program->id,
            'edge_locked_by_device_id' => null,
        ]);
    }

    /** @test */
    public function session_end_clears_dump_requested_flag(): void
    {
        $site    = $this->makeSite();
        $program = $this->makeProgram($site);
        $plainToken = 'tok-' . uniqid();
        $device  = EdgeDevice::create([
            'site_id'             => $site->id,
            'name'                => 'Test Pi',
            'device_token_hash'   => hash('sha256', $plainToken),
            'id_offset'           => 10_000_000,
            'sync_mode'           => 'auto',
            'supervisor_admin_access' => false,
            'assigned_program_id' => $program->id,
            'session_active'      => true,
            'dump_requested'      => true,
            'paired_at'           => now(),
        ]);
        $program->update(['edge_locked_by_device_id' => $device->id]);

        $this->withToken($plainToken)
            ->postJson('/api/edge/session/end')
            ->assertOk();

        $this->assertDatabaseHas('edge_devices', [
            'id'              => $device->id,
            'dump_requested'  => false,
        ]);
    }

    // ── E4.5 Dump session flow ────────────────────────────────────────────

    /** @test */
    public function dump_endpoint_sets_dump_requested_on_device(): void
    {
        $site    = $this->makeSite();
        $admin   = $this->makeAdmin($site);
        $program = $this->makeProgram($site);
        $plainToken = 'tok-' . uniqid();
        $device  = EdgeDevice::create([
            'site_id'             => $site->id,
            'name'                => 'Test Pi',
            'device_token_hash'   => hash('sha256', $plainToken),
            'id_offset'           => 10_000_000,
            'sync_mode'           => 'auto',
            'supervisor_admin_access' => false,
            'assigned_program_id' => $program->id,
            'session_active'      => true,
            'dump_requested'      => false,
            'paired_at'           => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/edge-devices/{$device->id}/dump")
            ->assertOk()
            ->assertJson(['message' => 'Dump signal sent.']);

        $this->assertDatabaseHas('edge_devices', [
            'id'             => $device->id,
            'dump_requested' => true,
        ]);
    }

    /** @test */
    public function heartbeat_response_includes_dump_session_true_when_dump_requested(): void
    {
        $site    = $this->makeSite();
        $plainToken = 'tok-' . uniqid();
        $device  = EdgeDevice::create([
            'site_id'             => $site->id,
            'name'                => 'Test Pi',
            'device_token_hash'   => hash('sha256', $plainToken),
            'id_offset'           => 10_000_000,
            'sync_mode'           => 'auto',
            'supervisor_admin_access' => false,
            'session_active'      => true,
            'dump_requested'      => true,
            'paired_at'           => now(),
        ]);

        $response = $this->withToken($plainToken)
            ->postJson('/api/edge/heartbeat', [
                'session_active'  => true,
                'sync_mode'       => 'auto',
                'last_synced_at'  => null,
                'package_version' => null,
                'app_version'     => null,
            ]);

        $response->assertOk()->assertJson(['dump_session' => true]);
    }

    /** @test */
    public function heartbeat_response_dump_session_is_false_when_not_requested(): void
    {
        $site    = $this->makeSite();
        $plainToken = 'tok-' . uniqid();
        EdgeDevice::create([
            'site_id'             => $site->id,
            'name'                => 'Test Pi',
            'device_token_hash'   => hash('sha256', $plainToken),
            'id_offset'           => 10_000_000,
            'sync_mode'           => 'auto',
            'supervisor_admin_access' => false,
            'session_active'      => true,
            'dump_requested'      => false,
            'paired_at'           => now(),
        ]);

        $this->withToken($plainToken)
            ->postJson('/api/edge/heartbeat', [
                'session_active'  => true,
                'sync_mode'       => 'auto',
                'last_synced_at'  => null,
                'package_version' => null,
                'app_version'     => null,
            ])
            ->assertOk()
            ->assertJson(['dump_session' => false]);
    }

    // ── E4.6 Session-active guard on reassignment ─────────────────────────

    /** @test */
    public function update_returns_422_when_changing_program_and_session_is_active(): void
    {
        $site     = $this->makeSite();
        $admin    = $this->makeAdmin($site);
        $program1 = $this->makeProgram($site);
        $program2 = $this->makeProgram($site);
        $device   = EdgeDevice::create([
            'site_id'             => $site->id,
            'name'                => 'Test Pi',
            'device_token_hash'   => hash('sha256', 'tok'),
            'id_offset'           => 10_000_000,
            'sync_mode'           => 'auto',
            'supervisor_admin_access' => false,
            'assigned_program_id' => $program1->id,
            'session_active'      => true,
            'paired_at'           => now(),
        ]);

        $this->actingAs($admin)
            ->putJson("/api/admin/edge-devices/{$device->id}", [
                'assigned_program_id'     => $program2->id,
                'sync_mode'               => 'auto',
                'supervisor_admin_access' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Cannot reassign program while a session is active. End or dump the session first.']);
    }

    /** @test */
    public function update_allows_non_program_changes_when_session_is_active(): void
    {
        $site    = $this->makeSite();
        $admin   = $this->makeAdmin($site);
        $program = $this->makeProgram($site);
        $device  = EdgeDevice::create([
            'site_id'             => $site->id,
            'name'                => 'Test Pi',
            'device_token_hash'   => hash('sha256', 'tok2'),
            'id_offset'           => 10_000_000,
            'sync_mode'           => 'auto',
            'supervisor_admin_access' => false,
            'assigned_program_id' => $program->id,
            'session_active'      => true,
            'paired_at'           => now(),
        ]);
        $program->update(['edge_locked_by_device_id' => $device->id]);

        // Only changing sync_mode and supervisor — same program_id — should pass
        $this->actingAs($admin)
            ->putJson("/api/admin/edge-devices/{$device->id}", [
                'assigned_program_id'     => $program->id,
                'sync_mode'               => 'end_of_event',
                'supervisor_admin_access' => true,
            ])
            ->assertOk();
    }
}
