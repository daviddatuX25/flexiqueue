<?php

namespace Tests\Unit\Services;

use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\User;
use App\Services\StationQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StationQueueServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_program_footer_stats_returns_zeros_when_no_program(): void
    {
        $service = app(StationQueueService::class);

        $result = $service->getProgramFooterStats(null);

        $this->assertSame(0, $result['queue_count']);
        $this->assertSame(0, $result['processed_today']);
    }

    public function test_get_program_footer_stats_returns_zeros_when_program_has_no_sessions(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $service = app(StationQueueService::class);

        $result = $service->getProgramFooterStats($program);

        $this->assertSame(0, $result['queue_count']);
        $this->assertSame(0, $result['processed_today']);
    }

    public function test_get_queue_for_station_excludes_held_sessions_from_serving_includes_in_holding(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'client_capacity' => 2,
            'holding_capacity' => 3,
            'is_active' => true,
        ]);
        $process = Process::create(['program_id' => $program->id, 'name' => 'P1', 'description' => null]);
        DB::table('station_process')->insert([['station_id' => $station->id, 'process_id' => $process->id]]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'T1',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();
        $heldSession = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'client_category' => 'Regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'serving',
            'is_on_hold' => true,
            'holding_station_id' => $station->id,
            'held_at' => now(),
            'held_order' => 1,
        ]);
        $token->update(['current_session_id' => $heldSession->id]);

        $service = app(StationQueueService::class);
        $result = $service->getQueueForStation($station);

        $this->assertArrayHasKey('holding', $result);
        $this->assertCount(1, $result['holding']);
        $this->assertSame($heldSession->id, $result['holding'][0]['session_id']);
        $this->assertSame('A1', $result['holding'][0]['alias']);
        $this->assertArrayHasKey('held_at', $result['holding'][0]);
        $servingIds = array_column($result['serving'], 'session_id');
        $this->assertNotContains($heldSession->id, $servingIds);
        $this->assertSame(3, $result['station']['holding_capacity']);
        $this->assertSame(1, $result['station']['holding_count']);
    }
}
