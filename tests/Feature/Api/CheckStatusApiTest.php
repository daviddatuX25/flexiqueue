<?php

namespace Tests\Feature\Api;

use App\Models\Process;
use App\Models\Program;
use App\Models\Session;
use App\Models\ServiceTrack;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per 08-API-SPEC-PHASE1 §2.1: GET /api/check-status/{qr_hash}. Public, no auth.
 */
class CheckStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_status_token_not_found_returns_404(): void
    {
        $response = $this->getJson('/api/check-status/'.Str::random(64));

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Token not found.');
    }

    public function test_check_status_available_token_returns_200_with_message(): void
    {
        $hash = hash('sha256', 'avail-'.Str::random(8));
        $token = new Token;
        $token->qr_code_hash = $hash;
        $token->physical_id = 'A1';
        $token->status = 'available';
        $token->save();

        $response = $this->getJson('/api/check-status/'.$hash);

        $response->assertStatus(200);
        $response->assertJsonPath('alias', 'A1');
        $response->assertJsonPath('status', 'available');
        $response->assertJsonPath('message', 'This token is not currently in use.');
    }

    public function test_check_status_soft_deleted_token_returns_404(): void
    {
        $hash = hash('sha256', 'deleted-'.Str::random(8));
        $token = new Token;
        $token->qr_code_hash = $hash;
        $token->physical_id = 'B2';
        $token->status = 'available';
        $token->save();
        $token->delete(); // Soft delete

        $response = $this->getJson('/api/check-status/'.$hash);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Token not found.');
    }

    public function test_check_status_in_use_returns_200_with_full_payload(): void
    {
        $user = \App\Models\User::factory()->create();
        $program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Interview',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Priority',
            'is_default' => true,
            'color_code' => '#F59E0B',
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        $hash = hash('sha256', 'inuse-'.Str::random(8));
        $token = new Token;
        $token->qr_code_hash = $hash;
        $token->physical_id = 'D4';
        $token->status = 'in_use';
        $token->save();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'D4',
            'client_category' => 'PWD / Senior / Pregnant',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'serving',
            'started_at' => now(),
        ]);
        $token->update(['current_session_id' => $session->id]);

        $response = $this->getJson('/api/check-status/'.$hash);

        $response->assertStatus(200);
        $response->assertJsonPath('alias', 'D4');
        $response->assertJsonPath('track', 'Priority');
        $response->assertJsonPath('client_category', 'PWD / Senior / Pregnant');
        $response->assertJsonPath('status', 'serving');
        $response->assertJsonPath('current_station', 'Interview');
        $response->assertJsonPath('progress.total_steps', 1);
        $response->assertJsonPath('progress.current_step', 1);
        $response->assertJsonStructure([
            'alias',
            'track',
            'client_category',
            'status',
            'current_station',
            'progress' => [
                'total_steps',
                'current_step',
                'steps',
            ],
            'started_at',
        ]);
        // Per 05-SECURITY-CONTROLS: no internal IDs in response
        $response->assertJsonMissingPath('session_id');
        $response->assertJsonMissingPath('token_id');
        // Per flexiqueue-5l7: response includes estimated_wait_minutes (0 when only one step / no remaining)
        $response->assertJsonPath('estimated_wait_minutes', 0);
    }

    /** Per flexiqueue-5l7: estimated_wait_minutes = sum of remaining steps' estimated_minutes. */
    public function test_check_status_in_use_includes_estimated_wait_minutes_from_remaining_steps(): void
    {
        $user = \App\Models\User::factory()->create();
        $program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $station1 = Station::create([
            'program_id' => $program->id,
            'name' => 'Step1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $station2 = Station::create([
            'program_id' => $program->id,
            'name' => 'Step2',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $process1 = Process::create([
            'program_id' => $program->id,
            'name' => 'Verify',
            'description' => null,
        ]);
        $process2 = Process::create([
            'program_id' => $program->id,
            'name' => 'Pay',
            'description' => null,
        ]);
        $station1->processes()->attach($process1->id);
        $station2->processes()->attach($process2->id);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station1->id,
            'process_id' => $process1->id,
            'step_order' => 1,
            'is_required' => true,
            'estimated_minutes' => 2,
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station2->id,
            'process_id' => $process2->id,
            'step_order' => 2,
            'is_required' => true,
            'estimated_minutes' => 5,
        ]);
        $hash = hash('sha256', 'wait-'.Str::random(8));
        $token = new Token;
        $token->qr_code_hash = $hash;
        $token->physical_id = 'G7';
        $token->status = 'in_use';
        $token->save();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'G7',
            'client_category' => 'Regular',
            'current_station_id' => $station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'started_at' => now(),
        ]);
        $token->update(['current_session_id' => $session->id]);

        $response = $this->getJson('/api/check-status/'.$hash);

        $response->assertStatus(200);
        $response->assertJsonPath('estimated_wait_minutes', 5);
    }

    public function test_check_status_does_not_require_auth(): void
    {
        $hash = hash('sha256', 'guest-'.Str::random(8));
        $token = new Token;
        $token->qr_code_hash = $hash;
        $token->physical_id = 'E5';
        $token->status = 'available';
        $token->save();

        $response = $this->getJson('/api/check-status/'.$hash);

        $response->assertStatus(200);
        $response->assertJsonPath('alias', 'E5');
    }
}
