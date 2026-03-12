<?php

namespace Tests\Feature\Api;

use App\Events\StationActivity;
use App\Models\Client;
use App\Models\ClientIdDocument;
use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per 08-API-SPEC-PHASE1 §3.1: POST /api/sessions/bind. Auth: any staff.
 */
class SessionBindTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Program $program;

    private ServiceTrack $track;

    private Station $station;

    private Token $token;

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
            'name' => 'First Station',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $process = Process::create(['program_id' => $this->program->id, 'name' => 'First Station', 'description' => null]);
        \Illuminate\Support\Facades\DB::table('station_process')->insert([
            'station_id' => $this->station->id,
            'process_id' => $process->id,
        ]);
        $this->track = ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        TrackStep::create([
            'track_id' => $this->track->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        $this->token = new Token;
        $this->token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $this->token->physical_id = 'A1';
        $this->token->status = 'available';
        $this->token->save();
    }

    public function test_bind_creates_session_returns_201(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/sessions/bind', [
            'qr_hash' => $this->token->qr_code_hash,
            'track_id' => $this->track->id,
            'client_category' => 'PWD',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('session.alias', 'A1');
        $response->assertJsonPath('session.client_category', 'PWD');
        $response->assertJsonPath('session.status', 'waiting');
        $response->assertJsonPath('session.current_step_order', 1);
        $response->assertJsonPath('token.physical_id', 'A1');
        $response->assertJsonPath('token.status', 'in_use');
        $this->assertDatabaseHas('queue_sessions', ['alias' => 'A1', 'status' => 'waiting']);
        $this->assertDatabaseHas('tokens', ['id' => $this->token->id, 'status' => 'in_use']);
        $this->assertDatabaseHas('transaction_logs', ['action_type' => 'bind']);
    }

    /** Per ISSUES-ELABORATION §10: display board updates in real time when token bound at triage */
    public function test_bind_dispatches_station_activity_for_display_board(): void
    {
        Event::fake([StationActivity::class]);

        $this->actingAs($this->staff)->postJson('/api/sessions/bind', [
            'qr_hash' => $this->token->qr_code_hash,
            'track_id' => $this->track->id,
            'client_category' => 'Regular',
        ])->assertStatus(201);

        Event::assertDispatched(StationActivity::class, function (StationActivity $event) {
            return $event->actionType === 'bind'
                && $event->alias === 'A1'
                && $event->stationId === $this->station->id
                && str_contains($event->message, 'registered at triage');
        });
    }

    public function test_bind_deactivated_token_returns_422(): void
    {
        $this->token->update(['status' => 'deactivated']);

        $response = $this->actingAs($this->staff)->postJson('/api/sessions/bind', [
            'qr_hash' => $this->token->qr_code_hash,
            'track_id' => $this->track->id,
            'client_category' => 'PWD',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('deactivated', $response->json('message') ?? '');
    }

    public function test_bind_token_not_found_returns_422(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/sessions/bind', [
            'qr_hash' => 'nonexistent',
            'track_id' => $this->track->id,
            'client_category' => 'PWD',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.qr_hash.0', 'Token not found.');
    }

    public function test_bind_track_not_in_program_returns_422(): void
    {
        $otherProgram = Program::create([
            'name' => 'Other',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->staff->id,
        ]);
        $otherTrack = ServiceTrack::create([
            'program_id' => $otherProgram->id,
            'name' => 'Other Track',
            'is_default' => true,
        ]);

        $response = $this->actingAs($this->staff)->postJson('/api/sessions/bind', [
            'qr_hash' => $this->token->qr_code_hash,
            'track_id' => $otherTrack->id,
            'client_category' => 'PWD',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.track_id.0', 'Track does not belong to the active program.');
    }

    public function test_bind_token_in_use_returns_409(): void
    {
        $session = \App\Models\Session::create([
            'token_id' => $this->token->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'A1',
            'client_category' => 'PWD',
            'current_station_id' => $this->station->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);
        $this->token->update(['status' => 'in_use', 'current_session_id' => $session->id]);

        $response = $this->actingAs($this->staff)->postJson('/api/sessions/bind', [
            'qr_hash' => $this->token->qr_code_hash,
            'track_id' => $this->track->id,
            'client_category' => 'PWD',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Token is already in use.');
        $response->assertJsonPath('active_session.alias', 'A1');
    }

    public function test_bind_returns_409_when_client_already_has_active_session(): void
    {
        $client = Client::factory()->create();
        $idDocument = ClientIdDocument::create([
            'client_id' => $client->id,
            'id_type' => 'PhilHealth',
            'id_number_encrypted' => encrypt('1234567890'),
            'id_number_hash' => 'hash',
        ]);

        $firstToken = new Token;
        $firstToken->qr_code_hash = hash('sha256', Str::random(32).'B1');
        $firstToken->physical_id = 'B1';
        $firstToken->status = 'in_use';
        $firstToken->save();

        $existingSession = \App\Models\Session::create([
            'token_id' => $firstToken->id,
            'program_id' => $this->program->id,
            'track_id' => $this->track->id,
            'alias' => 'B1',
            'client_id' => $client->id,
            'client_category' => 'Regular',
            'current_station_id' => $this->station->id,
            'current_step_order' => 1,
            'station_queue_position' => 1,
            'status' => 'waiting',
            'queued_at_station' => now(),
        ]);

        $secondToken = new Token;
        $secondToken->qr_code_hash = hash('sha256', Str::random(32).'C1');
        $secondToken->physical_id = 'C1';
        $secondToken->status = 'available';
        $secondToken->save();

        $response = $this->actingAs($this->staff)->postJson('/api/sessions/bind', [
            'qr_hash' => $secondToken->qr_code_hash,
            'track_id' => $this->track->id,
            'client_category' => 'Regular',
            'client_binding' => [
                'client_id' => $client->id,
                'source' => 'existing_id_document',
                'id_document_id' => $idDocument->id,
            ],
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'client_already_queued');
        $response->assertJsonPath('active_session.id', $existingSession->id);
        $response->assertJsonPath('active_session.alias', 'B1');
        $this->assertDatabaseMissing('queue_sessions', [
            'token_id' => $secondToken->id,
        ]);
    }

    public function test_bind_token_soft_deleted_returns_422(): void
    {
        $qrHash = $this->token->qr_code_hash;
        $this->token->delete(); // Soft delete – token excluded from lookup

        $response = $this->actingAs($this->staff)->postJson('/api/sessions/bind', [
            'qr_hash' => $qrHash,
            'track_id' => $this->track->id,
            'client_category' => 'PWD',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.qr_hash.0', 'Token not found.');
    }

    /**
     * Per PROCESS-STATION-REFACTOR: Bind with process-based track step routes via StationSelectionService.
     */
    /**
     * Per PROCESS-STATION-REFACTOR Edge 11: Bind fails when first process has 0 stations.
     */
    public function test_bind_first_process_has_no_stations_returns_422(): void
    {
        $process = Process::create([
            'program_id' => $this->program->id,
            'name' => 'Orphan Process',
            'description' => null,
        ]);
        // No stations attached to process; resolveFirstStationForStep uses StationSelectionService → null

        $step = $this->track->trackSteps()->first();
        $step->update(['process_id' => $process->id]);

        $response = $this->actingAs($this->staff)->postJson('/api/sessions/bind', [
            'qr_hash' => $this->token->qr_code_hash,
            'track_id' => $this->track->id,
            'client_category' => 'PWD',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('no stations', strtolower($response->json('message') ?? ''));
    }

    /**
     * Per PROCESS-STATION-REFACTOR: Bind with process-based track step routes via StationSelectionService.
     */
    public function test_bind_with_process_based_track_routes_to_station(): void
    {
        $process = Process::create([
            'program_id' => $this->program->id,
            'name' => 'Verification',
            'description' => null,
        ]);
        \Illuminate\Support\Facades\DB::table('station_process')->insert([
            'station_id' => $this->station->id,
            'process_id' => $process->id,
        ]);

        $step = $this->track->trackSteps()->first();
        $step->update(['process_id' => $process->id]);

        $response = $this->actingAs($this->staff)->postJson('/api/sessions/bind', [
            'qr_hash' => $this->token->qr_code_hash,
            'track_id' => $this->track->id,
            'client_category' => 'PWD',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('session.current_station.id', $this->station->id);
        $response->assertJsonPath('session.current_step_order', 1);
        $this->assertDatabaseHas('queue_sessions', ['current_station_id' => $this->station->id]);
    }

    public function test_bind_no_active_program_returns_400(): void
    {
        $this->program->update(['is_active' => false]);

        $response = $this->actingAs($this->staff)->postJson('/api/sessions/bind', [
            'qr_hash' => $this->token->qr_code_hash,
            'track_id' => $this->track->id,
            'client_category' => 'PWD',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'No active program. Please activate a program first.');
    }

    public function test_guest_cannot_bind_returns_401(): void
    {
        $response = $this->postJson('/api/sessions/bind', [
            'qr_hash' => $this->token->qr_code_hash,
            'track_id' => $this->track->id,
            'client_category' => 'PWD',
        ]);

        $response->assertStatus(401);
    }

    public function test_token_lookup_returns_qr_hash(): void
    {
        $response = $this->actingAs($this->staff)->getJson('/api/sessions/token-lookup?physical_id=A1');

        $response->assertStatus(200);
        $response->assertJsonPath('physical_id', 'A1');
        $response->assertJsonPath('qr_hash', $this->token->qr_code_hash);
        $response->assertJsonPath('status', 'available');
    }

    public function test_token_lookup_not_found_returns_404(): void
    {
        $response = $this->actingAs($this->staff)->getJson('/api/sessions/token-lookup?physical_id=Z99');

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Token not found.');
    }

    public function test_token_lookup_by_qr_hash_returns_physical_id(): void
    {
        $response = $this->actingAs($this->staff)->getJson(
            '/api/sessions/token-lookup?qr_hash='.urlencode($this->token->qr_code_hash)
        );

        $response->assertStatus(200);
        $response->assertJsonPath('physical_id', 'A1');
        $response->assertJsonPath('qr_hash', $this->token->qr_code_hash);
        $response->assertJsonPath('status', 'available');
    }

    /** Per ISSUES-ELABORATION §11: deactivated token returns 200 with status so frontend can show "Token deactivated." */
    public function test_token_lookup_deactivated_returns_200_with_status_deactivated(): void
    {
        $this->token->update(['status' => 'deactivated']);

        $response = $this->actingAs($this->staff)->getJson('/api/sessions/token-lookup?physical_id=A1');

        $response->assertStatus(200);
        $response->assertJsonPath('physical_id', 'A1');
        $response->assertJsonPath('status', 'deactivated');
    }

    /** Per ISSUES-ELABORATION §11: scan attempts logged; not_found flags potentially fabricated. */
    public function test_token_lookup_logs_scan_to_triage_scan_log(): void
    {
        $this->actingAs($this->staff)->getJson('/api/sessions/token-lookup?physical_id=A1');
        $this->assertDatabaseHas('triage_scan_log', [
            'physical_id' => 'A1',
            'result' => 'available',
            'token_id' => $this->token->id,
        ]);

        $this->actingAs($this->staff)->getJson('/api/sessions/token-lookup?physical_id=Z99');
        $this->assertDatabaseHas('triage_scan_log', [
            'physical_id' => 'Z99',
            'result' => 'not_found',
            'token_id' => null,
        ]);
    }
}
