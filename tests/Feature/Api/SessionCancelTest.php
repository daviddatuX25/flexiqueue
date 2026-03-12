<?php

namespace Tests\Feature\Api;

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
 * Per 08-API-SPEC-PHASE1 §3.5: POST /api/sessions/{id}/cancel.
 */
class SessionCancelTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Program $program;

    private ServiceTrack $track;

    private Station $station;

    private Token $token;

    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->create(['role' => 'staff']);
        $this->program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $this->staff->id,
        ]);
        $this->station = Station::create([
            'program_id' => $this->program->id,
            'name' => 'Station 1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $this->track = ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        TrackStep::create([
            'track_id' => $this->track->id,
            'station_id' => $this->station->id,
            'step_order' => 1,
            'is_required' => true,
        ]);

        $this->token = new Token;
        $this->token->qr_code_hash = hash('sha256', Str::random(32).'C1');
        $this->token->physical_id = 'C1';
        $this->token->status = 'in_use';
        $this->token->save();

        $this->session = Session::create([
            'token_id' => $this->token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'C1',
            'client_category' => 'Regular',
            'current_station_id' => $this->station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        $this->token->update(['current_session_id' => $this->session->id]);
    }

    public function test_cancel_waiting_session_returns_200_and_sets_cancelled(): void
    {
        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/cancel");

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'cancelled');
        $response->assertJsonPath('token.status', 'available');
        $this->assertDatabaseHas('queue_sessions', ['id' => $this->session->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('tokens', ['id' => $this->token->id, 'status' => 'available', 'current_session_id' => null]);
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'cancel']);
    }

    public function test_cancel_called_session_returns_200_and_sets_cancelled(): void
    {
        $this->session->update(['status' => 'called']);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/cancel");

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'cancelled');
        $this->assertDatabaseHas('queue_sessions', ['id' => $this->session->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'cancel']);
    }

    public function test_cancel_serving_session_returns_200_and_sets_cancelled(): void
    {
        $this->session->update(['status' => 'serving']);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/cancel");

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'cancelled');
        $this->assertDatabaseHas('queue_sessions', ['id' => $this->session->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'cancel']);
    }

    public function test_cancel_completed_session_returns_409(): void
    {
        $this->session->update(['status' => 'completed']);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/cancel");

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Session is already completed.');
    }
}

