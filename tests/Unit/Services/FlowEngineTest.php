<?php

namespace Tests\Unit\Services;

use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\User;
use App\Services\FlowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per 03-FLOW-ENGINE §2: FlowEngine::calculateNextStation.
 */
class FlowEngineTest extends TestCase
{
    use RefreshDatabase;

    private FlowEngine $flowEngine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flowEngine = new FlowEngine;
    }

    public function test_calculate_next_station_returns_next_step_when_exists(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $station1 = Station::create([
            'program_id' => $program->id,
            'name' => 'First',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $station2 = Station::create([
            'program_id' => $program->id,
            'name' => 'Second',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station1->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station2->id,
            'step_order' => 2,
            'is_required' => true,
        ]);

        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $station1->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);

        $result = $this->flowEngine->calculateNextStation($session);

        $this->assertNotNull($result);
        $this->assertSame($station2->id, $result['station_id']);
        $this->assertSame(2, $result['step_order']);
    }

    public function test_calculate_next_station_returns_null_when_flow_complete(): void
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
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station->id,
            'step_order' => 1,
            'is_required' => true,
        ]);

        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);

        $result = $this->flowEngine->calculateNextStation($session);

        $this->assertNull($result);
    }

    public function test_calculate_next_station_returns_null_when_next_station_inactive(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $station1 = Station::create([
            'program_id' => $program->id,
            'name' => 'First',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $station2 = Station::create([
            'program_id' => $program->id,
            'name' => 'Second',
            'capacity' => 1,
            'is_active' => false,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station1->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station2->id,
            'step_order' => 2,
            'is_required' => true,
        ]);

        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $station1->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);

        $result = $this->flowEngine->calculateNextStation($session);

        $this->assertNull($result);
    }

    public function test_calculate_next_station_uses_override_steps_when_set(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $s1 = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $s2 = Station::create([
            'program_id' => $program->id,
            'name' => 'S2',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
        ]);

        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $s1->id,
            'current_step_order' => 1,
            'override_steps' => [$s1->id, $s2->id],
            'status' => 'serving',
        ]);

        $result = $this->flowEngine->calculateNextStation($session);

        $this->assertNotNull($result);
        $this->assertSame($s2->id, $result['station_id']);
        $this->assertSame(2, $result['step_order']);
    }

    public function test_calculate_next_station_returns_null_when_override_steps_complete(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $s1 = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
        ]);

        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $s1->id,
            'current_step_order' => 1,
            'override_steps' => [$s1->id],
            'status' => 'serving',
        ]);

        $result = $this->flowEngine->calculateNextStation($session);

        $this->assertNull($result);
    }
}
