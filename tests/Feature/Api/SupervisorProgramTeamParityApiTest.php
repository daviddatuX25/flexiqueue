<?php

namespace Tests\Feature\Api;

use App\Models\Program;
use App\Models\RbacTeam;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Program-team `programs.supervise` matches legacy pivot for SessionPolicy, StationPolicy, and supervisor PIN bypass
 * (see {@see StaffProgramAccessService::mayBypassSupervisorInteractiveAuth}). R4: {@see User::isSupervisorForProgram}
 * is true for program-team grants without pivot.
 */
class SupervisorProgramTeamParityApiTest extends TestCase
{
    use RefreshDatabase;

    private function grantProgramsSuperviseOnProgramTeam(User $user, Program $program): void
    {
        RbacTeam::forProgram($program);
        $previous = getPermissionsTeamId();
        setPermissionsTeamId(RbacTeam::forProgram($program)->id);
        try {
            $user->givePermissionTo(PermissionCatalog::PROGRAMS_SUPERVISE);
        } finally {
            setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * Program-team supervise only: no program_supervisors pivot, no assigned station — still may call (SessionPolicy update).
     */
    public function test_program_team_supervise_can_call_session_without_assigned_station_or_pivot(): void
    {
        $staff = User::factory()->create([
            'assigned_station_id' => null,
        ]);
        $program = Program::create([
            'name' => 'Prog team only',
            'description' => null,
            'is_active' => true,
            'created_by' => $staff->id,
            'site_id' => null,
        ]);
        $this->grantProgramsSuperviseOnProgramTeam($staff, $program);
        $staff->refresh();
        $this->assertTrue(
            $staff->isSupervisorForProgram($program->id),
            'R4: program-team supervise counts as supervisor without program_supervisors pivot',
        );

        $station1 = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
            'priority_first_override' => false,
        ]);
        $station2 = Station::create([
            'program_id' => $program->id,
            'name' => 'S2',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        TrackStep::create(['track_id' => $track->id, 'station_id' => $station1->id, 'step_order' => 1, 'is_required' => true]);
        TrackStep::create(['track_id' => $track->id, 'station_id' => $station2->id, 'step_order' => 2, 'is_required' => true]);

        $pwdToken = new Token;
        $pwdToken->qr_code_hash = hash('sha256', Str::random(32).'PWD2');
        $pwdToken->physical_id = 'PWD2';
        $pwdToken->status = 'in_use';
        $pwdToken->save();
        $pwdSession = Session::create([
            'token_id' => $pwdToken->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'PWD2',
            'client_category' => 'PWD',
            'current_station_id' => $station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'queued_at_station' => now()->subMinutes(2),
        ]);
        $pwdToken->update(['current_session_id' => $pwdSession->id]);

        $regularToken = new Token;
        $regularToken->qr_code_hash = hash('sha256', Str::random(32).'REG2');
        $regularToken->physical_id = 'REG2';
        $regularToken->status = 'in_use';
        $regularToken->save();
        $regularSession = Session::create([
            'token_id' => $regularToken->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'REG2',
            'client_category' => 'Regular',
            'current_station_id' => $station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'queued_at_station' => now()->subMinutes(5),
        ]);
        $regularToken->update(['current_session_id' => $regularSession->id]);

        $response = $this->actingAs($staff)->postJson("/api/sessions/{$regularSession->id}/call", []);

        $response->assertStatus(200);
        $regularSession->refresh();
        $this->assertSame('called', $regularSession->status);
    }

    /**
     * StationPolicy: program-team supervise on program A does not allow queue view on program B’s station.
     */
    public function test_station_queue_denied_when_program_team_supervise_is_for_different_program(): void
    {
        $staff = User::factory()->create([
            'assigned_station_id' => null,
        ]);
        $programA = Program::create([
            'name' => 'Program A',
            'slug' => 'pa-'.Str::random(6),
            'description' => null,
            'is_active' => true,
            'created_by' => $staff->id,
            'site_id' => null,
        ]);
        $programB = Program::create([
            'name' => 'Program B',
            'slug' => 'pb-'.Str::random(6),
            'description' => null,
            'is_active' => true,
            'created_by' => $staff->id,
            'site_id' => null,
        ]);
        $this->grantProgramsSuperviseOnProgramTeam($staff, $programA);

        $stationA = Station::create([
            'program_id' => $programA->id,
            'name' => 'Desk A',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $stationB = Station::create([
            'program_id' => $programB->id,
            'name' => 'Desk B',
            'capacity' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($staff)->getJson("/api/stations/{$stationB->id}/queue")->assertStatus(403);
        $this->actingAs($staff)->getJson("/api/stations/{$stationA->id}/queue")->assertStatus(200);
    }

    public function test_call_skips_supervisor_pin_when_program_team_supervise_matches_session_program(): void
    {
        $staff = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $staff->id,
            'site_id' => null,
        ]);
        $this->grantProgramsSuperviseOnProgramTeam($staff, $program);

        $station1 = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
            'priority_first_override' => false,
        ]);
        $station2 = Station::create([
            'program_id' => $program->id,
            'name' => 'S2',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        TrackStep::create(['track_id' => $track->id, 'station_id' => $station1->id, 'step_order' => 1, 'is_required' => true]);
        TrackStep::create(['track_id' => $track->id, 'station_id' => $station2->id, 'step_order' => 2, 'is_required' => true]);

        $pwdToken = new Token;
        $pwdToken->qr_code_hash = hash('sha256', Str::random(32).'PWD');
        $pwdToken->physical_id = 'PWD';
        $pwdToken->status = 'in_use';
        $pwdToken->save();
        $pwdSession = Session::create([
            'token_id' => $pwdToken->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'PWD1',
            'client_category' => 'PWD',
            'current_station_id' => $station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'queued_at_station' => now()->subMinutes(2),
        ]);
        $pwdToken->update(['current_session_id' => $pwdSession->id]);

        $regularToken = new Token;
        $regularToken->qr_code_hash = hash('sha256', Str::random(32).'REG');
        $regularToken->physical_id = 'REG';
        $regularToken->status = 'in_use';
        $regularToken->save();
        $regularSession = Session::create([
            'token_id' => $regularToken->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'REG1',
            'client_category' => 'Regular',
            'current_station_id' => $station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'queued_at_station' => now()->subMinutes(5),
        ]);
        $regularToken->update(['current_session_id' => $regularSession->id]);

        $staff->update(['assigned_station_id' => $station1->id]);

        $response = $this->actingAs($staff)->postJson("/api/sessions/{$regularSession->id}/call", []);

        $response->assertStatus(200);
        $regularSession->refresh();
        $this->assertSame('called', $regularSession->status);
    }
}
