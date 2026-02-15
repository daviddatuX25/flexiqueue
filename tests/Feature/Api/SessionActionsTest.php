<?php

namespace Tests\Feature\Api;

use App\Events\StationActivity;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per 08-API-SPEC-PHASE1 §3.2–3.8: Session actions (call, transfer, complete, cancel, no-show, force-complete).
 */
class SessionActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Program $program;

    private ServiceTrack $track;

    private ServiceTrack $trackToStation2;

    private Station $station1;

    private Station $station2;

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
        $this->station1 = Station::create([
            'program_id' => $this->program->id,
            'name' => 'First Station',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $this->station2 = Station::create([
            'program_id' => $this->program->id,
            'name' => 'Second Station',
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
            'station_id' => $this->station1->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        TrackStep::create([
            'track_id' => $this->track->id,
            'station_id' => $this->station2->id,
            'step_order' => 2,
            'is_required' => true,
        ]);
        $this->trackToStation2 = ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'To S2',
            'is_default' => false,
            'color_code' => '#666',
        ]);
        TrackStep::create([
            'track_id' => $this->trackToStation2->id,
            'station_id' => $this->station2->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        $this->token = new Token;
        $this->token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $this->token->physical_id = 'A1';
        $this->token->status = 'in_use';
        $this->token->save();
        $this->session = Session::create([
            'token_id' => $this->token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A1',
            'client_category' => 'PWD',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        $this->token->update(['current_session_id' => $this->session->id]);
    }

    public function test_call_waiting_session_returns_200_and_sets_called(): void
    {
        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/call");

        $response->assertStatus(200);
        $response->assertJsonPath('session_id', $this->session->id);
        $response->assertJsonPath('alias', 'A1');
        $response->assertJsonPath('no_show_attempts', 0);
        $response->assertJsonPath('status', 'called');
        $this->assertDatabaseHas('queue_sessions', ['id' => $this->session->id, 'status' => 'called']);
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'call']);
    }

    public function test_call_dispatches_station_activity_broadcast(): void
    {
        Event::fake([StationActivity::class]);

        $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/call");

        Event::assertDispatched(StationActivity::class, function (StationActivity $event) {
            return $event->stationId === $this->station1->id
                && $event->stationName === 'First Station'
                && str_contains($event->message, 'A1')
                && str_contains($event->message, 'priority lane')
                && $event->alias === 'A1'
                && $event->actionType === 'call';
        });
    }

    public function test_serve_dispatches_station_activity_broadcast(): void
    {
        $this->session->update(['status' => 'called']);
        Event::fake([StationActivity::class]);

        $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/serve");

        Event::assertDispatched(StationActivity::class, function (StationActivity $event) {
            return $event->stationId === $this->station1->id
                && $event->stationName === 'First Station'
                && str_contains($event->message, 'A1')
                && str_contains($event->message, 'arrived (serving)')
                && $event->alias === 'A1'
                && $event->actionType === 'check_in';
        });
    }

    public function test_serve_called_session_returns_200_and_sets_serving(): void
    {
        $this->session->update(['status' => 'called']);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/serve");

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'serving');
        $this->assertDatabaseHas('queue_sessions', ['id' => $this->session->id, 'status' => 'serving']);
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'check_in']);
    }

    public function test_call_non_waiting_session_returns_409(): void
    {
        $this->session->update(['status' => 'serving']);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/call");

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Session is not waiting at current station.');
    }

    public function test_transfer_standard_moves_to_next_station(): void
    {
        $this->session->update(['status' => 'serving']);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/transfer", [
            'mode' => 'standard',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'waiting');
        $response->assertJsonPath('session.current_station.id', $this->station2->id);
        $response->assertJsonPath('session.current_step_order', 2);
        $this->assertDatabaseHas('queue_sessions', ['id' => $this->session->id, 'current_station_id' => $this->station2->id]);
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'transfer']);
    }

    public function test_transfer_flow_complete_returns_action_required(): void
    {
        $this->session->update([
            'status' => 'serving',
            'current_station_id' => $this->station2->id,
            'current_step_order' => 2,
        ]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/transfer", [
            'mode' => 'standard',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('action_required', 'complete');
        $response->assertJsonPath('session.status', 'serving');
    }

    public function test_transfer_non_serving_returns_409(): void
    {
        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/transfer", [
            'mode' => 'standard',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Session is not currently being served. Cannot transfer.');
    }

    public function test_complete_at_final_station_returns_200(): void
    {
        $this->session->update([
            'status' => 'serving',
            'current_station_id' => $this->station2->id,
            'current_step_order' => 2,
        ]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/complete");

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'completed');
        $response->assertJsonPath('token.status', 'available');
        $this->assertDatabaseHas('queue_sessions', ['id' => $this->session->id, 'status' => 'completed']);
        $this->assertDatabaseHas('tokens', ['id' => $this->token->id, 'status' => 'available', 'current_session_id' => null]);
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'complete']);
    }

    public function test_complete_with_steps_remaining_returns_409(): void
    {
        $this->session->update(['status' => 'serving', 'current_step_order' => 1]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/complete");

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Cannot complete: required steps remaining.');
        $response->assertJsonStructure(['remaining_steps']);
    }

    public function test_cancel_active_session_returns_200(): void
    {
        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/cancel", [
            'remarks' => 'Client left',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'cancelled');
        $response->assertJsonPath('token.status', 'available');
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'cancel']);
    }

    public function test_no_show_on_called_session_with_3_attempts_returns_200_and_terminates(): void
    {
        $this->session->update(['status' => 'called', 'no_show_attempts' => 2]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/no-show");

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'no_show');
        $response->assertJsonPath('token.status', 'available');
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'no_show']);
    }

    public function test_no_show_on_called_session_with_1_attempt_returns_to_waiting(): void
    {
        $this->session->update(['status' => 'called', 'no_show_attempts' => 0]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/no-show");

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'waiting');
        $response->assertJsonPath('back_to_waiting', true);
        $response->assertJsonPath('no_show_attempts', 1);
    }

    public function test_force_complete_with_valid_pin_returns_200(): void
    {
        $supervisor = User::factory()->supervisor()->withOverridePin('123456')->create();
        $this->program->supervisedBy()->attach($supervisor->id);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/force-complete", [
            'reason' => 'Token accidentally reused',
            'supervisor_user_id' => $supervisor->id,
            'supervisor_pin' => '123456',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'completed');
        $response->assertJsonPath('token.status', 'available');
        $this->assertDatabaseHas('transaction_logs', [
            'session_id' => $this->session->id,
            'action_type' => 'force_complete',
        ]);
    }

    public function test_force_complete_invalid_pin_returns_401(): void
    {
        $supervisor = User::factory()->supervisor()->withOverridePin('123456')->create();
        $this->program->supervisedBy()->attach($supervisor->id);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/force-complete", [
            'reason' => 'Token accidentally reused',
            'supervisor_user_id' => $supervisor->id,
            'supervisor_pin' => '999999',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Invalid supervisor PIN.');
    }

    public function test_override_with_valid_pin_returns_200(): void
    {
        $supervisor = User::factory()->supervisor()->withOverridePin('123456')->create();
        $this->program->supervisedBy()->attach($supervisor->id);
        $this->session->update(['status' => 'serving']);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/override", [
            'target_track_id' => $this->trackToStation2->id,
            'reason' => 'Skip to final step',
            'supervisor_user_id' => $supervisor->id,
            'supervisor_pin' => '123456',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'waiting');
        $response->assertJsonPath('session.current_station.id', $this->station2->id);
        $response->assertJsonStructure(['override' => ['authorized_by', 'reason']]);
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'override']);
    }
}
