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
use App\Services\SessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unit tests for SessionService. Per REFACTORING-ISSUE-LIST Issue 6: callRequiresOverrideAuth.
 */
class SessionServiceTest extends TestCase
{
    use RefreshDatabase;

    private SessionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SessionService::class);
    }

    public function test_call_requires_override_auth_returns_false_when_session_status_is_not_waiting(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['require_permission_before_override' => true, 'priority_first' => false],
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $token = $this->createToken();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $this->createTrack($program)->id,
            'alias' => 'A1',
            'client_category' => 'Regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'called',
        ]);

        $result = $this->service->callRequiresOverrideAuth($session);

        $this->assertFalse($result);
    }

    public function test_call_requires_override_auth_returns_false_when_session_has_no_current_station(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['require_permission_before_override' => true, 'priority_first' => false],
        ]);
        $token = $this->createToken();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $this->createTrack($program)->id,
            'alias' => 'A1',
            'client_category' => 'Regular',
            'current_station_id' => null,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);

        $result = $this->service->callRequiresOverrideAuth($session);

        $this->assertFalse($result);
    }

    public function test_call_requires_override_auth_returns_false_when_program_does_not_require_permission(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['require_permission_before_override' => false, 'priority_first' => false],
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $track = $this->createTrack($program);
        $token = $this->createToken();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'client_category' => 'Regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        $this->createPriorityWaitingSessionAtStation($station, $program, $track);

        $result = $this->service->callRequiresOverrideAuth($session);

        $this->assertFalse($result);
    }

    public function test_call_requires_override_auth_returns_false_when_priority_first_is_true(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['require_permission_before_override' => true, 'priority_first' => true],
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $track = $this->createTrack($program);
        $token = $this->createToken();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'client_category' => 'Regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        $this->createPriorityWaitingSessionAtStation($station, $program, $track);

        $result = $this->service->callRequiresOverrideAuth($session);

        $this->assertFalse($result);
    }

    public function test_call_requires_override_auth_returns_false_when_station_priority_first_override_is_true(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['require_permission_before_override' => true, 'priority_first' => false],
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
            'priority_first_override' => true,
        ]);
        $track = $this->createTrack($program);
        $token = $this->createToken();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'client_category' => 'Regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        $this->createPriorityWaitingSessionAtStation($station, $program, $track);

        $result = $this->service->callRequiresOverrideAuth($session);

        $this->assertFalse($result);
    }

    public function test_call_requires_override_auth_returns_false_when_session_client_category_is_priority(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['require_permission_before_override' => true, 'priority_first' => false],
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $track = $this->createTrack($program);
        $token = $this->createToken();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'client_category' => 'PWD',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        $this->createPriorityWaitingSessionAtStation($station, $program, $track);

        $result = $this->service->callRequiresOverrideAuth($session);

        $this->assertFalse($result);
    }

    public function test_call_requires_override_auth_returns_false_when_no_other_priority_waiting_at_station(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['require_permission_before_override' => true, 'priority_first' => false],
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $token = $this->createToken();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $this->createTrack($program)->id,
            'alias' => 'A1',
            'client_category' => 'Regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        // No other priority session at same station

        $result = $this->service->callRequiresOverrideAuth($session);

        $this->assertFalse($result);
    }

    public function test_call_requires_override_auth_returns_true_when_regular_session_would_skip_priority_waiting(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['require_permission_before_override' => true, 'priority_first' => false],
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $track = $this->createTrack($program);
        $token = $this->createToken();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'client_category' => 'Regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        $this->createPriorityWaitingSessionAtStation($station, $program, $track);

        $result = $this->service->callRequiresOverrideAuth($session);

        $this->assertTrue($result);
    }

    private function createToken(): Token
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'t1');
        $token->physical_id = 'T1';
        $token->status = 'in_use';
        $token->save();

        return $token;
    }

    private function createTrack(Program $program): ServiceTrack
    {
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'P1',
            'description' => null,
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
        ]);

        return $track;
    }

    private function createPriorityWaitingSessionAtStation(Station $station, Program $program, ServiceTrack $track): Session
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'t2');
        $token->physical_id = 'T2';
        $token->status = 'in_use';
        $token->save();

        return Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A2',
            'client_category' => 'PWD',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
    }
}
