<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per docs/plans/TRACK-OVERRIDES-REFACTOR.md §1: schema for awaiting_approval, override_steps, permission_requests track-based.
 */
class ProgramOverridesRefactorSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_can_have_awaiting_approval_status(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
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
            'alias' => $token->physical_id,
            'client_category' => 'Regular',
            'current_station_id' => null,
            'current_step_order' => 1,
            'status' => 'awaiting_approval',
        ]);

        $this->assertDatabaseHas('queue_sessions', [
            'id' => $session->id,
            'status' => 'awaiting_approval',
            'current_station_id' => null,
        ]);
    }

    public function test_session_can_store_override_steps(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
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
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A2');
        $token->physical_id = 'A2';
        $token->status = 'in_use';
        $token->save();

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => $token->physical_id,
            'client_category' => 'Regular',
            'current_station_id' => $s1->id,
            'current_step_order' => 1,
            'override_steps' => [$s1->id, $s2->id],
            'status' => 'waiting',
        ]);

        $session->refresh();
        $this->assertSame([$s1->id, $s2->id], $session->override_steps);
    }

    public function test_permission_request_create_sets_session_awaiting_approval_for_override(): void
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
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
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $s1->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A3');
        $token->physical_id = 'A3';
        $token->status = 'in_use';
        $token->save();

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A3',
            'current_station_id' => $s1->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);

        $prService = app(\App\Services\PermissionRequestService::class);
        $prService->create($session, 'override', $user->id, 'Needs help', $s2->id);

        $session->refresh();
        $this->assertSame('awaiting_approval', $session->status);
        $this->assertNull($session->current_station_id);
    }
}

