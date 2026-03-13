<?php

namespace Tests\Feature\Api;

use App\Events\StationActivity;
use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $process1 = Process::create(['program_id' => $this->program->id, 'name' => 'First Station', 'description' => null]);
        $process2 = Process::create(['program_id' => $this->program->id, 'name' => 'Second Station', 'description' => null]);
        DB::table('station_process')->insert([
            ['station_id' => $this->station1->id, 'process_id' => $process1->id],
            ['station_id' => $this->station2->id, 'process_id' => $process2->id],
        ]);
        $this->track = ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        TrackStep::create([
            'track_id' => $this->track->id,
            'process_id' => $process1->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        TrackStep::create([
            'track_id' => $this->track->id,
            'process_id' => $process2->id,
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
            'process_id' => $process2->id,
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

    /**
     * Guards Call Next on SQLite: transaction_logs must accept action_type 'call' (migrations 2025_02_15 + 2026_03_02).
     */
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
            return $event->programId === $this->program->id
                && $event->stationId === $this->station1->id
                && $event->stationName === 'First Station'
                && str_contains($event->message, 'A1')
                && str_contains($event->message, 'priority lane')
                && $event->alias === 'A1'
                && $event->actionType === 'call';
        });
    }

    public function test_call_ignores_held_sessions_for_station_capacity(): void
    {
        $this->station1->update(['client_capacity' => 1]);

        $heldToken = new Token;
        $heldToken->qr_code_hash = hash('sha256', Str::random(32).'H1');
        $heldToken->physical_id = 'H1';
        $heldToken->status = 'in_use';
        $heldToken->save();
        $heldSession = Session::create([
            'token_id' => $heldToken->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'H1',
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'serving',
            'is_on_hold' => true,
            'holding_station_id' => $this->station1->id,
            'held_at' => now(),
            'held_order' => 1,
        ]);
        $heldToken->update(['current_session_id' => $heldSession->id]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/call");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'called');
        $this->assertDatabaseHas('queue_sessions', ['id' => $this->session->id, 'status' => 'called']);
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'call']);
    }

    /** Display activity: only indicate "priority lane" when client has priority classification; regular client must not get "priority lane" in message. */
    public function test_call_regular_client_does_not_include_priority_lane_in_station_activity_message(): void
    {
        $this->session->update(['client_category' => 'regular']);
        Event::fake([StationActivity::class]);

        $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/call");

        Event::assertDispatched(StationActivity::class, function (StationActivity $event) {
            return $event->programId === $this->program->id
                && $event->stationId === $this->station1->id
                && $event->alias === 'A1'
                && $event->actionType === 'call'
                && str_contains($event->message, 'A1')
                && str_contains($event->message, 'called')
                && ! str_contains($event->message, 'priority lane');
        });
    }

    public function test_serve_dispatches_station_activity_broadcast(): void
    {
        $this->session->update(['status' => 'called']);
        Event::fake([StationActivity::class]);

        $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/serve");

        Event::assertDispatched(StationActivity::class, function (StationActivity $event) {
            return $event->programId === $this->program->id
                && $event->stationId === $this->station1->id
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

    public function test_serve_waiting_session_with_correct_station_id_returns_200_and_sets_serving(): void
    {
        $this->session->update(['status' => 'waiting']);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/serve", [
            'station_id' => $this->station1->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'serving');
        $this->assertDatabaseHas('queue_sessions', ['id' => $this->session->id, 'status' => 'serving']);
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'check_in']);
    }

    public function test_serve_waiting_session_without_station_id_returns_422(): void
    {
        $this->session->update(['status' => 'waiting']);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/serve", []);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.station_id.0', 'Station context is required when serving from waiting.');
        $this->assertDatabaseHas('queue_sessions', ['id' => $this->session->id, 'status' => 'waiting']);
    }

    public function test_serve_waiting_session_at_another_station_returns_409(): void
    {
        $this->session->update(['status' => 'waiting', 'current_station_id' => $this->station1->id]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/serve", [
            'station_id' => $this->station2->id,
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Session is not at this station.');
        $this->assertDatabaseHas('queue_sessions', ['id' => $this->session->id, 'status' => 'waiting']);
    }

    public function test_serve_waiting_session_when_station_at_capacity_returns_409(): void
    {
        $this->station1->update(['client_capacity' => 1]);
        $this->session->update(['status' => 'called', 'current_station_id' => $this->station1->id]);
        $token2 = new Token;
        $token2->qr_code_hash = hash('sha256', Str::random(32).'A2');
        $token2->physical_id = 'A2';
        $token2->status = 'in_use';
        $token2->save();
        $session2 = Session::create([
            'token_id' => $token2->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A2',
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
        ]);
        $token2->update(['current_session_id' => $session2->id]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$session2->id}/serve", [
            'station_id' => $this->station1->id,
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Station at capacity (1). Cannot start serving more clients.');
        $this->assertDatabaseHas('queue_sessions', ['id' => $session2->id, 'status' => 'waiting']);
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

    public function test_enqueue_back_from_called_returns_200_and_moves_to_end_of_queue(): void
    {
        $this->staff->update(['assigned_station_id' => $this->station1->id]);
        $this->session->update([
            'status' => 'called',
            'station_queue_position' => 1,
            'no_show_attempts' => 2,
        ]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/enqueue-back");

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'waiting');
        $response->assertJsonPath('back_to_waiting', true);
        $this->session->refresh();
        $this->assertSame(2, $this->session->no_show_attempts);
        $this->assertGreaterThanOrEqual(2, $this->session->station_queue_position);
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'enqueue_back']);
    }

    public function test_enqueue_back_from_serving_returns_200_and_moves_to_end_of_queue(): void
    {
        $this->staff->update(['assigned_station_id' => $this->station1->id]);
        $this->session->update([
            'status' => 'serving',
            'station_queue_position' => 1,
            'no_show_attempts' => 1,
        ]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/enqueue-back");

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'waiting');
        $response->assertJsonPath('back_to_waiting', true);
        $this->session->refresh();
        $this->assertSame(1, $this->session->no_show_attempts);
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'enqueue_back']);
    }

    public function test_enqueue_back_from_waiting_returns_409(): void
    {
        $this->staff->update(['assigned_station_id' => $this->station1->id]);
        $this->session->update(['status' => 'waiting']);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/enqueue-back");

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Enqueue back only applies to called or serving sessions.');
        $this->assertDatabaseMissing('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'enqueue_back']);
    }

    public function test_no_show_on_called_session_at_2_attempts_returns_to_waiting_not_terminate(): void
    {
        $this->staff->update(['assigned_station_id' => $this->station1->id]);
        $this->session->update(['status' => 'called', 'no_show_attempts' => 2]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/no-show");

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'waiting');
        $response->assertJsonPath('back_to_waiting', true);
        $response->assertJsonPath('no_show_attempts', 3);
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'no_show']);
    }

    public function test_no_show_at_max_with_last_call_terminates(): void
    {
        $this->staff->update(['assigned_station_id' => $this->station1->id]);
        $this->session->update(['status' => 'called', 'no_show_attempts' => 3]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/no-show", [
            'last_call' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'no_show');
        $response->assertJsonPath('token.status', 'available');
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'no_show']);
    }

    public function test_no_show_at_max_with_extend_returns_to_waiting(): void
    {
        $this->staff->update(['assigned_station_id' => $this->station1->id]);
        $this->session->update(['status' => 'called', 'no_show_attempts' => 3]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/no-show", [
            'extend' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'waiting');
        $response->assertJsonPath('back_to_waiting', true);
        $response->assertJsonPath('no_show_attempts', 4);
        $response->assertJsonPath('extended', true);
        $this->session->refresh();
        $this->assertSame(4, $this->session->no_show_attempts);
    }

    public function test_no_show_at_max_without_extend_or_last_call_returns_422(): void
    {
        $this->staff->update(['assigned_station_id' => $this->station1->id]);
        $this->session->update(['status' => 'called', 'no_show_attempts' => 3]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/no-show");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'At max no-show attempts. Use extend or last_call.');
    }

    public function test_no_show_with_enqueue_back_moves_to_end_of_queue(): void
    {
        $this->staff->update(['assigned_station_id' => $this->station1->id]);
        $this->session->update([
            'status' => 'called',
            'station_queue_position' => 1,
            'no_show_attempts' => 0,
        ]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/no-show", [
            'enqueue_back' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'waiting');
        $this->session->refresh();
        $this->assertGreaterThanOrEqual(2, $this->session->station_queue_position);
    }

    public function test_no_show_from_serving_returns_to_waiting(): void
    {
        $this->staff->update(['assigned_station_id' => $this->station1->id]);
        $this->session->update(['status' => 'serving', 'no_show_attempts' => 0]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/no-show");

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'waiting');
        $response->assertJsonPath('no_show_attempts', 1);
    }

    public function test_no_show_on_called_session_with_1_attempt_returns_to_waiting(): void
    {
        $this->staff->update(['assigned_station_id' => $this->station1->id]);
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

    /** Per flexiqueue-i87: When require_permission_before_override is OFF, staff can force-complete with reason only (no PIN). */
    public function test_force_complete_with_reason_only_when_require_permission_off_returns_200(): void
    {
        $this->program->update(['settings' => ['require_permission_before_override' => false]]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/force-complete", [
            'reason' => 'Client left without completing',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'completed');
        $response->assertJsonPath('token.status', 'available');
        $this->assertDatabaseHas('transaction_logs', [
            'session_id' => $this->session->id,
            'action_type' => 'force_complete',
        ]);
    }

    /** Per flexiqueue-i87: When require_permission_before_override is OFF, staff can override with reason only (no PIN). */
    public function test_override_with_reason_only_when_require_permission_off_returns_200(): void
    {
        $this->program->update(['settings' => ['require_permission_before_override' => false]]);
        $this->session->update(['status' => 'serving']);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/override", [
            'target_track_id' => $this->trackToStation2->id,
            'reason' => 'Skip to final step',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'waiting');
        $response->assertJsonPath('session.current_station.id', $this->station2->id);
        $response->assertJsonStructure(['override' => ['authorized_by', 'reason']]);
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'override']);
    }

    /** Per flexiqueue-eiju: predefined track override without reason succeeds. */
    public function test_override_predefined_track_without_reason_returns_200(): void
    {
        $this->program->update(['settings' => ['require_permission_before_override' => false]]);
        $this->session->update(['status' => 'serving']);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/override", [
            'target_track_id' => $this->trackToStation2->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'waiting');
        $response->assertJsonPath('session.current_station.id', $this->station2->id);
        $response->assertJsonStructure(['override' => ['authorized_by', 'reason']]);
        $response->assertJsonPath('override.reason', '');
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'override']);
    }

    /** Per flexiqueue-eiju: custom path override without reason returns 422. */
    public function test_override_custom_steps_without_reason_returns_422(): void
    {
        $this->program->update(['settings' => ['require_permission_before_override' => false]]);
        $this->session->update(['status' => 'serving']);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/override", [
            'target_track_id' => $this->trackToStation2->id,
            'custom_steps' => [$this->station2->id, $this->station1->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reason']);
    }

    /** When require_permission_before_override is ON (default), staff without PIN gets 401. */
    public function test_force_complete_without_auth_when_require_permission_on_returns_401(): void
    {
        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/force-complete", [
            'reason' => 'Client left',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Supervisor authorization required.');
    }

    /** When require_permission_before_override is ON (default), staff without PIN gets 401 for override. */
    public function test_override_without_auth_when_require_permission_on_returns_401(): void
    {
        $this->session->update(['status' => 'serving']);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/override", [
            'target_track_id' => $this->trackToStation2->id,
            'reason' => 'Skip to final step',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Supervisor authorization required.');
    }
}
