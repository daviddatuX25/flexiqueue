<?php

namespace Tests\Unit\Services;

use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TransactionLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Services\StationSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per PROCESS-STATION-REFACTOR §4.2: StationSelectionService selects station for a process.
 */
class StationSelectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private StationSelectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StationSelectionService::class);
    }

    public function test_select_station_returns_station_when_process_has_one(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Only',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'Verification',
            'description' => null,
        ]);
        DB::table('station_process')->insert(['station_id' => $station->id, 'process_id' => $process->id]);

        $result = $this->service->selectStationForProcess($process->id, $program->id);

        $this->assertSame($station->id, $result);
    }

    public function test_select_station_returns_null_when_process_not_found(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $result = $this->service->selectStationForProcess(99999, $program->id);

        $this->assertNull($result);
    }

    public function test_select_station_returns_null_when_process_has_no_stations(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'Orphan',
            'description' => null,
        ]);

        $result = $this->service->selectStationForProcess($process->id, $program->id);

        $this->assertNull($result);
    }

    public function test_select_station_returns_null_when_all_stations_inactive(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Inactive',
            'capacity' => 1,
            'is_active' => false,
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'Orphan',
            'description' => null,
        ]);
        DB::table('station_process')->insert(['station_id' => $station->id, 'process_id' => $process->id]);

        $result = $this->service->selectStationForProcess($process->id, $program->id);

        $this->assertNull($result);
    }

    public function test_select_station_shortest_queue_picks_station_with_fewest_waiting(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['station_selection_mode' => 'shortest_queue'],
        ]);
        $station1 = Station::create([
            'program_id' => $program->id,
            'name' => 'A',
            'capacity' => 10,
            'is_active' => true,
        ]);
        $station2 = Station::create([
            'program_id' => $program->id,
            'name' => 'B',
            'capacity' => 10,
            'is_active' => true,
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'Verification',
            'description' => null,
        ]);
        DB::table('station_process')->insert([
            ['station_id' => $station1->id, 'process_id' => $process->id],
            ['station_id' => $station2->id, 'process_id' => $process->id],
        ]);

        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
        ]);

        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'T1');
        $token->physical_id = 'T1';
        $token->status = 'in_use';
        $token->save();

        Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'T1',
            'current_station_id' => $station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);

        $result = $this->service->selectStationForProcess($process->id, $program->id);

        $this->assertSame($station2->id, $result);
    }

    public function test_select_station_round_robin_rotates_to_next_station(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['station_selection_mode' => 'round_robin'],
        ]);
        $station1 = Station::create([
            'program_id' => $program->id,
            'name' => 'A',
            'capacity' => 10,
            'is_active' => true,
        ]);
        $station2 = Station::create([
            'program_id' => $program->id,
            'name' => 'B',
            'capacity' => 10,
            'is_active' => true,
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'Verification',
            'description' => null,
        ]);
        DB::table('station_process')->insert([
            ['station_id' => $station1->id, 'process_id' => $process->id],
            ['station_id' => $station2->id, 'process_id' => $process->id],
        ]);

        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
        ]);

        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'T1');
        $token->physical_id = 'T1';
        $token->status = 'in_use';
        $token->save();

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'T1',
            'current_station_id' => $station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);

        TransactionLog::create([
            'session_id' => $session->id,
            'station_id' => null,
            'staff_user_id' => $user->id,
            'action_type' => 'transfer',
            'next_station_id' => $station1->id,
        ]);

        $result = $this->service->selectStationForProcess($process->id, $program->id);

        $this->assertSame($station2->id, $result);
    }
}
