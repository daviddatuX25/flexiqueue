<?php

namespace Tests\Feature;

use App\Models\PermissionRequest;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\User;
use App\Services\PermissionRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class PermissionRequestServiceOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_override_with_target_station_id_moves_session_and_logs_transaction(): void
    {
        Event::fake();

        $supervisor = User::factory()->create();
        $requester = User::factory()->create();

        $program = Program::create([
            'name' => 'Test Program',
            'is_active' => true,
            'created_by' => $supervisor->id,
        ]);

        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Main',
            'is_default' => true,
        ]);

        $fromStation = Station::create([
            'program_id' => $program->id,
            'name' => 'From',
            'capacity' => 1,
            'is_active' => true,
        ]);

        $toStation = Station::create([
            'program_id' => $program->id,
            'name' => 'To',
            'capacity' => 1,
            'is_active' => true,
        ]);

        $token = new Token();
        $token->qr_code_hash = hash('sha256', Str::random(32) . 'X1');
        $token->physical_id = 'X1';
        $token->status = 'in_use';
        $token->save();

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => $token->physical_id,
            'client_category' => 'Regular',
            'current_station_id' => $fromStation->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);

        $permissionRequest = PermissionRequest::create([
            'session_id' => $session->id,
            'action_type' => PermissionRequest::ACTION_OVERRIDE,
            'requester_user_id' => $requester->id,
            'status' => PermissionRequest::STATUS_PENDING,
            'target_station_id' => $toStation->id,
            'reason' => 'Move to different station',
        ]);

        /** @var PermissionRequestService $service */
        $service = app(PermissionRequestService::class);

        $result = $service->approve($permissionRequest, $supervisor->id);

        $this->assertArrayHasKey('session', $result);
        $this->assertArrayHasKey('override', $result);

        $sessionData = $result['session'];
        $this->assertSame($session->id, $sessionData['id']);
        $this->assertSame('waiting', $sessionData['status']);
        $this->assertNotNull($sessionData['current_station']);
        $this->assertSame($toStation->id, $sessionData['current_station']['id']);

        $session->refresh();
        $this->assertSame('waiting', $session->status);
        $this->assertSame($toStation->id, $session->current_station_id);
        $this->assertSame([$toStation->id], $session->override_steps);

        $this->assertDatabaseHas('transaction_logs', [
            'session_id' => $session->id,
            'action_type' => 'override',
            'next_station_id' => $toStation->id,
            'remarks' => 'Move to different station',
        ]);

        $overrideData = $result['override'];
        $this->assertSame('Move to different station', $overrideData['reason']);
        $this->assertStringContainsString($supervisor->name, $overrideData['authorized_by']);
    }

    public function test_approve_override_with_no_path_throws_422(): void
    {
        Event::fake();

        $supervisor = User::factory()->create();
        $requester = User::factory()->create();

        $program = Program::create([
            'name' => 'Test Program',
            'is_active' => true,
            'created_by' => $supervisor->id,
        ]);

        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Main',
            'is_default' => true,
        ]);

        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);

        $token = new Token();
        $token->qr_code_hash = hash('sha256', Str::random(32) . 'X2');
        $token->physical_id = 'X2';
        $token->status = 'in_use';
        $token->save();

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => $token->physical_id,
            'client_category' => 'Regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'awaiting_approval',
        ]);

        $permissionRequest = PermissionRequest::create([
            'session_id' => $session->id,
            'action_type' => PermissionRequest::ACTION_OVERRIDE,
            'requester_user_id' => $requester->id,
            'status' => PermissionRequest::STATUS_PENDING,
            'target_station_id' => null,
            'target_track_id' => null,
            'custom_steps' => null,
            'reason' => 'No path',
        ]);

        /** @var PermissionRequestService $service */
        $service = app(PermissionRequestService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->expectExceptionMessage('Override requires target track or custom path');

        $service->approve($permissionRequest, $supervisor->id);
    }
}

