<?php

namespace Tests\Unit\Services;

use App\Models\Process;
use App\Models\Program;
use App\Models\ProgramAuditLog;
use App\Models\ProgramStationAssignment;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\User;
use App\Services\ProgramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Per central-edge-v2-final Phase A.1: activate() (multi-program) and activateExclusive() (single-program) behavior.
 */
class ProgramServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProgramService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProgramService::class);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_activate_sets_program_active_and_does_not_change_other_active_program(): void
    {
        Auth::login($this->admin);

        $programA = Program::create([
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $programB = Program::create([
            'name' => 'Program B',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $this->addMinimalActivateSetup($programB);

        $this->service->activate($programB);

        $this->assertTrue($programA->fresh()->is_active);
        $this->assertTrue($programB->fresh()->is_active);
    }

    public function test_activate_creates_only_session_start_audit_log_for_activated_program(): void
    {
        Auth::login($this->admin);

        $programA = Program::create([
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $programB = Program::create([
            'name' => 'Program B',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $this->addMinimalActivateSetup($programB);

        $this->service->activate($programB);

        $this->assertDatabaseHas('program_audit_log', [
            'program_id' => $programB->id,
            'staff_user_id' => $this->admin->id,
            'action' => 'session_start',
        ]);
        $this->assertDatabaseMissing('program_audit_log', [
            'program_id' => $programA->id,
            'action' => 'session_stop',
        ]);
        $this->assertSame(1, ProgramAuditLog::where('program_id', $programB->id)->count());
    }

    public function test_activate_syncs_station_assignments_for_activated_program(): void
    {
        Auth::login($this->admin);

        $staff = User::factory()->create(['assigned_station_id' => null]);
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'P1',
            'description' => null,
        ]);
        DB::table('station_process')->insert([
            'station_id' => $station->id,
            'process_id' => $process->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ProgramStationAssignment::create([
            'program_id' => $program->id,
            'user_id' => $staff->id,
            'station_id' => $station->id,
        ]);
        ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'T1',
            'is_default' => true,
        ]);

        $this->service->activate($program);

        $staff->refresh();
        $this->assertSame($station->id, $staff->assigned_station_id);
    }

    public function test_activate_sets_assigned_staff_availability_to_away_by_default(): void
    {
        Auth::login($this->admin);

        $staff = User::factory()->create([
            'assigned_station_id' => null,
            'availability_status' => User::AVAILABILITY_AVAILABLE,
        ]);
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'P1',
            'description' => null,
        ]);
        DB::table('station_process')->insert([
            'station_id' => $station->id,
            'process_id' => $process->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ProgramStationAssignment::create([
            'program_id' => $program->id,
            'user_id' => $staff->id,
            'station_id' => $station->id,
        ]);
        ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'T1',
            'is_default' => true,
        ]);

        $this->service->activate($program);

        $staff->refresh();
        $this->assertSame(User::AVAILABILITY_AWAY, $staff->availability_status);
    }

    public function test_pause_and_resume_update_assigned_staff_availability(): void
    {
        Auth::login($this->admin);

        $staff = User::factory()->create([
            'availability_status' => User::AVAILABILITY_AVAILABLE,
        ]);
        $program = Program::create([
            'name' => 'P',
            'description' => null,
            'is_active' => true,
            'is_paused' => false,
            'created_by' => $this->admin->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        ProgramStationAssignment::create([
            'program_id' => $program->id,
            'user_id' => $staff->id,
            'station_id' => $station->id,
        ]);

        $this->service->pause($program);
        $staff->refresh();
        $this->assertSame(User::AVAILABILITY_ON_BREAK, $staff->availability_status);

        $this->service->resume($program->fresh());
        $staff->refresh();
        $this->assertSame(User::AVAILABILITY_AWAY, $staff->availability_status);
    }

    public function test_activate_exclusive_deactivates_other_and_activates_given_program(): void
    {
        Auth::login($this->admin);

        $programA = Program::create([
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $programB = Program::create([
            'name' => 'Program B',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $this->addMinimalActivateSetup($programB);

        $this->service->activateExclusive($programB);

        $this->assertFalse($programA->fresh()->is_active);
        $this->assertTrue($programB->fresh()->is_active);
        $this->assertDatabaseHas('program_audit_log', [
            'program_id' => $programA->id,
            'staff_user_id' => $this->admin->id,
            'action' => 'session_stop',
        ]);
        $this->assertDatabaseHas('program_audit_log', [
            'program_id' => $programB->id,
            'staff_user_id' => $this->admin->id,
            'action' => 'session_start',
        ]);
    }

    public function test_activate_exclusive_throws_when_another_program_has_active_sessions(): void
    {
        Auth::login($this->admin);

        $programA = Program::create([
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $programA->id,
            'name' => 'T1',
            'is_default' => true,
        ]);
        $station = Station::create([
            'program_id' => $programA->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $token = new Token;
        $token->qr_code_hash = str_repeat('a', 64);
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();
        Session::create([
            'token_id' => $token->id,
            'program_id' => $programA->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'no_show_attempts' => 0,
        ]);

        $programB = Program::create([
            'name' => 'Program B',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $this->addMinimalActivateSetup($programB);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Another program is currently running and has clients in the queue');

        $this->service->activateExclusive($programB);
    }

    public function test_activate_exclusive_does_not_change_state_when_another_has_active_sessions(): void
    {
        Auth::login($this->admin);

        $programA = Program::create([
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $programA->id,
            'name' => 'T1',
            'is_default' => true,
        ]);
        $station = Station::create([
            'program_id' => $programA->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $token = new Token;
        $token->qr_code_hash = str_repeat('b', 64);
        $token->physical_id = 'B1';
        $token->status = 'in_use';
        $token->save();
        Session::create([
            'token_id' => $token->id,
            'program_id' => $programA->id,
            'track_id' => $track->id,
            'alias' => 'B1',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'no_show_attempts' => 0,
        ]);

        $programB = Program::create([
            'name' => 'Program B',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $this->addMinimalActivateSetup($programB);

        try {
            $this->service->activateExclusive($programB);
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertTrue($programA->fresh()->is_active);
        $this->assertFalse($programB->fresh()->is_active);
    }

    public function test_activate_exclusive_when_no_program_active_creates_only_session_start(): void
    {
        Auth::login($this->admin);

        $program = Program::create([
            'name' => 'Only',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $this->addMinimalActivateSetup($program);

        $this->service->activateExclusive($program);

        $this->assertTrue($program->fresh()->is_active);
        $this->assertDatabaseHas('program_audit_log', [
            'program_id' => $program->id,
            'staff_user_id' => $this->admin->id,
            'action' => 'session_start',
        ]);
        $this->assertSame(1, ProgramAuditLog::count());
    }

    private function addMinimalActivateSetup(Program $program): void
    {
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'P1',
            'description' => null,
        ]);
        DB::table('station_process')->insert([
            'station_id' => $station->id,
            'process_id' => $process->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ProgramStationAssignment::create([
            'program_id' => $program->id,
            'user_id' => $this->admin->id,
            'station_id' => $station->id,
        ]);
        ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'T1',
            'is_default' => true,
        ]);
    }
}
