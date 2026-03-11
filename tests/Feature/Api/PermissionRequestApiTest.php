<?php

namespace Tests\Feature\Api;

use App\Models\PermissionRequest;
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
 * Per docs/plans/TRACK-OVERRIDES-REFACTOR.md: Permission request API with track-based override.
 */
class PermissionRequestApiTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $supervisor;

    private Program $program;

    private ServiceTrack $track;

    private ServiceTrack $trackToStation2;

    private Station $station1;

    private Station $station2;

    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['role' => 'staff']);
        $this->supervisor = User::factory()->supervisor()->withOverridePin('123456')->create();
        $this->program = Program::create([
            'name' => 'Test',
            'is_active' => true,
            'created_by' => $this->staff->id,
        ]);
        $this->program->supervisedBy()->attach($this->supervisor->id);
        $this->station1 = Station::create([
            'program_id' => $this->program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $this->station2 = Station::create([
            'program_id' => $this->program->id,
            'name' => 'S2',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $this->track = ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        TrackStep::create(['track_id' => $this->track->id, 'station_id' => $this->station1->id, 'step_order' => 1, 'is_required' => true]);
        TrackStep::create(['track_id' => $this->track->id, 'station_id' => $this->station2->id, 'step_order' => 2, 'is_required' => true]);
        $this->trackToStation2 = ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'To S2',
            'is_default' => false,
            'color_code' => '#666',
        ]);
        TrackStep::create(['track_id' => $this->trackToStation2->id, 'station_id' => $this->station2->id, 'step_order' => 1, 'is_required' => true]);
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();
        $this->session = Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A1',
            'client_category' => 'PWD',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);
        $token->update(['current_session_id' => $this->session->id]);
    }

    public function test_store_override_with_target_track_id_without_reason_creates_request(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/permission-requests', [
            'session_id' => $this->session->id,
            'action_type' => 'override',
            'target_track_id' => $this->trackToStation2->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'pending');

        $pr = PermissionRequest::where('session_id', $this->session->id)->first();
        $this->assertNotNull($pr);
        $this->assertSame($this->trackToStation2->id, $pr->target_track_id);
        $this->assertSame('override', $pr->action_type);
        $this->assertSame('', $pr->reason);

        $this->session->refresh();
        $this->assertSame('awaiting_approval', $this->session->status);
        $this->assertNull($this->session->current_station_id);
    }

    public function test_store_override_with_target_track_id_creates_request_and_sets_awaiting_approval(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/permission-requests', [
            'session_id' => $this->session->id,
            'action_type' => 'override',
            'reason' => 'Skip to final',
            'target_track_id' => $this->trackToStation2->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'pending');
        $response->assertJsonStructure(['id', 'status', 'message']);

        $pr = PermissionRequest::where('session_id', $this->session->id)->first();
        $this->assertNotNull($pr);
        $this->assertSame($this->trackToStation2->id, $pr->target_track_id);
        $this->assertSame('override', $pr->action_type);

        $this->session->refresh();
        $this->assertSame('awaiting_approval', $this->session->status);
        $this->assertNull($this->session->current_station_id);
    }

    public function test_approve_override_with_target_track_moves_session(): void
    {
        $pr = PermissionRequest::create([
            'session_id' => $this->session->id,
            'action_type' => 'override',
            'requester_user_id' => $this->staff->id,
            'status' => PermissionRequest::STATUS_PENDING,
            'target_track_id' => $this->trackToStation2->id,
            'reason' => 'Skip',
        ]);
        $this->session->update(['status' => 'awaiting_approval', 'current_station_id' => null]);

        $response = $this->actingAs($this->supervisor)->postJson("/api/permission-requests/{$pr->id}/approve", []);

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'waiting');
        $response->assertJsonPath('session.current_station.id', $this->station2->id);
        $response->assertJsonPath('session.track.id', $this->trackToStation2->id);

        $this->session->refresh();
        $this->assertSame('waiting', $this->session->status);
        $this->assertSame($this->station2->id, $this->session->current_station_id);
        $this->assertSame($this->trackToStation2->id, $this->session->track_id);
    }

    public function test_reject_with_reassign_track_id_moves_session_to_track(): void
    {
        $pr = PermissionRequest::create([
            'session_id' => $this->session->id,
            'action_type' => 'override',
            'requester_user_id' => $this->staff->id,
            'status' => PermissionRequest::STATUS_PENDING,
            'target_track_id' => $this->trackToStation2->id,
            'reason' => 'Skip',
        ]);
        $this->session->update(['status' => 'awaiting_approval', 'current_station_id' => null]);

        $response = $this->actingAs($this->supervisor)->postJson("/api/permission-requests/{$pr->id}/reject", [
            'reassign_track_id' => $this->trackToStation2->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Request rejected. Session reassigned.');
        $response->assertJsonPath('session.status', 'waiting');
        $response->assertJsonPath('session.track.id', $this->trackToStation2->id);

        $pr->refresh();
        $this->assertSame(PermissionRequest::STATUS_REJECTED, $pr->status);

        $this->session->refresh();
        $this->assertSame('waiting', $this->session->status);
        $this->assertSame($this->trackToStation2->id, $this->session->track_id);
        $this->assertSame($this->station2->id, $this->session->current_station_id);
    }

    public function test_store_override_with_is_custom_without_reason_returns_422(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/permission-requests', [
            'session_id' => $this->session->id,
            'action_type' => 'override',
            'is_custom' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reason']);
    }

    public function test_store_override_with_is_custom_creates_request_without_target_track(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/permission-requests', [
            'session_id' => $this->session->id,
            'action_type' => 'override',
            'reason' => 'Custom path needed',
            'is_custom' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'pending');

        $pr = PermissionRequest::where('session_id', $this->session->id)->first();
        $this->assertNotNull($pr);
        $this->assertNull($pr->target_track_id);

        $this->session->refresh();
        $this->assertSame('awaiting_approval', $this->session->status);
    }

    public function test_approve_override_with_custom_steps_moves_session_to_custom_path(): void
    {
        $pr = PermissionRequest::create([
            'session_id' => $this->session->id,
            'action_type' => 'override',
            'requester_user_id' => $this->staff->id,
            'status' => PermissionRequest::STATUS_PENDING,
            'target_track_id' => null,
            'reason' => 'Custom path',
        ]);
        $this->session->update(['status' => 'awaiting_approval', 'current_station_id' => null]);

        $response = $this->actingAs($this->supervisor)->postJson("/api/permission-requests/{$pr->id}/approve", [
            'custom_steps' => [$this->station2->id, $this->station1->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'waiting');
        $response->assertJsonPath('session.current_station.id', $this->station2->id);

        $this->session->refresh();
        $this->assertSame('waiting', $this->session->status);
        $this->assertSame($this->station2->id, $this->session->current_station_id);
        $this->assertSame([$this->station2->id, $this->station1->id], $this->session->override_steps);
    }

    public function test_reject_without_reassign_returns_simple_message(): void
    {
        $pr = PermissionRequest::create([
            'session_id' => $this->session->id,
            'action_type' => 'override',
            'requester_user_id' => $this->staff->id,
            'status' => PermissionRequest::STATUS_PENDING,
            'target_track_id' => $this->trackToStation2->id,
            'reason' => 'Skip',
        ]);
        $this->session->update(['status' => 'awaiting_approval', 'current_station_id' => null]);

        $response = $this->actingAs($this->supervisor)->postJson("/api/permission-requests/{$pr->id}/reject", []);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Request rejected.');
        $response->assertJsonMissing(['session']);

        $this->session->refresh();
        $this->assertSame('awaiting_approval', $this->session->status);
    }
}
