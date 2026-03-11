<?php

namespace Tests\Unit\Services;

use App\Models\Program;
use App\Models\ProgramStationAssignment;
use App\Models\Station;
use App\Models\User;
use App\Services\StaffAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per REFACTORING-ISSUE-LIST.md Issue 9: StaffAssignmentService::getStationForUser() resolves
 * assigned station for a user in a program (PSA first, then assigned_station_id fallback).
 */
class StaffAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private StaffAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StaffAssignmentService::class);
    }

    public function test_get_station_for_user_returns_station_from_program_station_assignment(): void
    {
        $user = User::factory()->create(['role' => 'staff', 'assigned_station_id' => null]);
        $program = Program::create([
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $stationX = Station::create([
            'program_id' => $program->id,
            'name' => 'Station X',
            'capacity' => 1,
            'is_active' => true,
        ]);
        ProgramStationAssignment::create([
            'program_id' => $program->id,
            'user_id' => $user->id,
            'station_id' => $stationX->id,
        ]);

        $result = $this->service->getStationForUser($user, $program->id);

        $this->assertNotNull($result);
        $this->assertSame($stationX->id, $result->id);
    }

    public function test_get_station_for_user_psa_wins_over_assigned_station_id(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $program = Program::create([
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $stationFromPsa = Station::create([
            'program_id' => $program->id,
            'name' => 'From PSA',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $stationFromColumn = Station::create([
            'program_id' => $program->id,
            'name' => 'From assigned_station_id',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $user->update(['assigned_station_id' => $stationFromColumn->id]);
        ProgramStationAssignment::create([
            'program_id' => $program->id,
            'user_id' => $user->id,
            'station_id' => $stationFromPsa->id,
        ]);

        $result = $this->service->getStationForUser($user, $program->id);

        $this->assertNotNull($result);
        $this->assertSame($stationFromPsa->id, $result->id);
    }

    public function test_get_station_for_user_returns_station_from_assigned_station_id_when_no_psa(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $program = Program::create([
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Fallback Station',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $user->update(['assigned_station_id' => $station->id]);

        $result = $this->service->getStationForUser($user, $program->id);

        $this->assertNotNull($result);
        $this->assertSame($station->id, $result->id);
    }

    public function test_get_station_for_user_returns_null_when_assigned_station_in_different_program(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $programA = Program::create([
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $programB = Program::create([
            'name' => 'Program B',
            'description' => null,
            'is_active' => false,
            'created_by' => $user->id,
        ]);
        $stationInB = Station::create([
            'program_id' => $programB->id,
            'name' => 'Station in B',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $user->update(['assigned_station_id' => $stationInB->id]);

        $result = $this->service->getStationForUser($user, $programA->id);

        $this->assertNull($result);
    }

    public function test_get_station_for_user_returns_null_when_no_assignment(): void
    {
        $user = User::factory()->create(['role' => 'staff', 'assigned_station_id' => null]);
        $program = Program::create([
            'name' => 'Program A',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $result = $this->service->getStationForUser($user, $program->id);

        $this->assertNull($result);
    }
}
