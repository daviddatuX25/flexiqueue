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
        return User::factory()->create(['site_id' => $site->id, 'role' => 'admin']);
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
}
