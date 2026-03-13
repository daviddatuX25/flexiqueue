<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientIdDocument;
use App\Models\IdentityRegistration;
use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\TransactionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Public self-serve triage: GET /triage/start, GET /api/public/token-lookup, POST /api/public/sessions/bind.
 * No auth. 403 when program allow_public_triage is false.
 */
class PublicTriageTest extends TestCase
{
    use RefreshDatabase;

    private function createProgramWithTracks(bool $allowPublicTriage = true, array $extraSettings = []): array
    {
        $user = User::factory()->create();
        $settings = array_merge(['allow_public_triage' => $allowPublicTriage], $extraSettings);
        $program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => $settings,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'First',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $process = Process::create(['program_id' => $program->id, 'name' => 'P1', 'description' => null]);
        \Illuminate\Support\Facades\DB::table('station_process')->insert([
            'station_id' => $station->id,
            'process_id' => $process->id,
        ]);
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => null,
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
        ]);

        return ['program' => $program, 'track' => $track, 'station' => $station, 'process' => $process];
    }

    private function createToken(string $physicalId = 'A1'): Token
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).$physicalId);
        $token->physical_id = $physicalId;
        $token->status = 'available';
        $token->save();

        return $token;
    }

    public function test_triage_start_returns_200_with_allowed_true_when_program_allows_public_triage(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(true, ['allow_unverified_entry' => true]);

        $response = $this->get('/triage/start');

        $response->assertRedirect('/public/triage/'.$program->id);
        $response = $this->get('/public/triage/'.$program->id);
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Triage/PublicStart')
            ->where('allowed', true)
            ->where('program_id', $program->id)
            ->has('program_name')
            ->has('tracks')
            ->has('date')
        );
        $props = $response->viewData('page')['props'];
        $this->assertSame('Test Program', $props['program_name']);
        $this->assertIsArray($props['tracks']);
        $this->assertCount(1, $props['tracks']);
        $this->assertSame('Default', $props['tracks'][0]['name']);
        $this->assertTrue($props['allow_unverified_entry']);
    }

    public function test_triage_start_returns_200_with_allowed_false_when_no_program(): void
    {
        $response = $this->get('/triage/start');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Triage/PublicStart')
            ->where('allowed', false)
            ->where('tracks', [])
        );
    }

    public function test_triage_start_returns_200_with_allowed_false_when_program_disallows_public_triage(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(false);

        // Per central-edge Phase A: when no program allows public triage, render selector (no redirect).
        $response = $this->get('/triage/start');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Triage/PublicStart')
            ->where('allowed', false)
        );
    }

    public function test_public_token_lookup_returns_200_when_allowed_and_token_exists(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->getJson('/api/public/token-lookup?qr_hash='.urlencode($token->qr_code_hash).'&program_id='.$program->id);

        $response->assertStatus(200);
        $response->assertJsonPath('physical_id', 'A1');
        $response->assertJsonPath('qr_hash', $token->qr_code_hash);
        $response->assertJsonPath('status', 'available');
    }

    public function test_public_token_lookup_returns_403_when_public_triage_disabled(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(false);
        $token = $this->createToken('A1');

        $response = $this->getJson('/api/public/token-lookup?qr_hash='.urlencode($token->qr_code_hash).'&program_id='.$program->id);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Public self-serve triage is not available.');
    }

    public function test_public_token_lookup_returns_404_when_token_not_found(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(true);

        $response = $this->getJson('/api/public/token-lookup?physical_id=Z99&program_id='.$program->id);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Token not found.');
    }

    public function test_public_bind_creates_session_returns_201_when_allowed(): void
    {
        ['program' => $program, 'track' => $track] = $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('session.alias', 'A1');
        $response->assertJsonPath('session.client_category', 'Regular');
        $response->assertJsonPath('session.status', 'waiting');
        $response->assertJsonPath('token.physical_id', 'A1');
        $response->assertJsonPath('token.status', 'in_use');
        $this->assertDatabaseHas('queue_sessions', ['alias' => 'A1', 'status' => 'waiting']);
        $this->assertDatabaseHas('tokens', ['id' => $token->id, 'status' => 'in_use']);
    }

    public function test_public_bind_returns_403_when_disabled(): void
    {
        ['program' => $program, 'track' => $track] = $this->createProgramWithTracks(false);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Public self-serve triage is not available.');
        $this->assertDatabaseMissing('queue_sessions', ['alias' => 'A1']);
    }

    public function test_public_bind_returns_409_when_token_in_use(): void
    {
        ['track' => $track, 'station' => $station, 'program' => $program] = $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');
        $session = \App\Models\Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'client_category' => 'Regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'station_queue_position' => 1,
            'status' => 'waiting',
            'queued_at_station' => now(),
        ]);
        $token->update(['status' => 'in_use', 'current_session_id' => $session->id]);

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Token is already in use.');
    }

    public function test_public_bind_transaction_log_has_null_staff_user_id(): void
    {
        ['program' => $program, 'track' => $track] = $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $log = TransactionLog::where('action_type', 'bind')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->staff_user_id);
    }

    public function test_public_bind_identity_registration_request_mutually_exclusive_with_client_binding_returns_422(): void
    {
        ['program' => $program, 'track' => $track] = $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_binding' => ['client_id' => 1, 'source' => 'test', 'id_document_id' => 1],
            'identity_registration_request' => ['name' => 'Jane'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['identity_registration_request']);
    }

    public function test_public_bind_identity_registration_request_allow_unverified_false_creates_registration_no_session(): void
    {
        ['track' => $track, 'program' => $program] = $this->createProgramWithTracks(true, ['allow_unverified_entry' => false]);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'identity_registration_request' => [
                'name' => 'Jane Doe',
                'birth_year' => 1990,
                'client_category' => 'Regular',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('request_submitted', true);
        $this->assertDatabaseHas('identity_registrations', [
            'program_id' => $program->id,
            'name' => 'Jane Doe',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('queue_sessions', ['token_id' => $token->id]);
        $token->refresh();
        $this->assertSame('available', $token->status);
    }

    public function test_public_bind_identity_registration_request_without_token_creates_registration_no_session(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(true, ['allow_unverified_entry' => true]);

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'identity_registration_request' => [
                'name' => 'Jane Doe',
                'birth_year' => 1990,
                'client_category' => 'Regular',
                'id_type' => 'PhilHealth',
                'id_number' => '1234567890',
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('request_submitted', true);
        $this->assertDatabaseHas('identity_registrations', [
            'program_id' => $program->id,
            'name' => 'Jane Doe',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'id_type' => 'PhilHealth',
            'status' => 'pending',
            'session_id' => null,
        ]);
    }

    public function test_public_bind_identity_registration_request_allow_unverified_true_creates_session_and_registration(): void
    {
        ['track' => $track, 'program' => $program, 'station' => $station] = $this->createProgramWithTracks(true, ['allow_unverified_entry' => true]);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'identity_registration_request' => [
                'name' => 'Jane Doe',
                'birth_year' => 1990,
                'client_category' => 'PWD / Senior / Pregnant',
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('session.alias', 'A1');
        $response->assertJsonPath('session.client_category', 'PWD / Senior / Pregnant');
        $response->assertJsonPath('unverified', true);
        $sessionId = $response->json('session.id');
        $this->assertDatabaseHas('queue_sessions', [
            'id' => $sessionId,
            'token_id' => $token->id,
            'client_id' => null,
            'client_category' => 'PWD / Senior / Pregnant',
        ]);
        $reg = IdentityRegistration::where('program_id', $program->id)->where('status', 'pending')->first();
        $this->assertNotNull($reg);
        $this->assertSame($sessionId, $reg->session_id);
        $this->assertSame('Jane Doe', $reg->name);
        $token->refresh();
        $this->assertSame('in_use', $token->status);
    }

    public function test_public_bind_reuses_existing_pending_identity_registration_for_same_id_when_allow_unverified_true(): void
    {
        ['track' => $track, 'program' => $program] = $this->createProgramWithTracks(true, ['allow_unverified_entry' => true]);
        $token = $this->createToken('A1');

        $idType = 'PhilHealth';
        $idNumber = '1234567890';
        $last4 = '7890';

        $existing = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'name' => 'Jane Doe',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'id_type' => $idType,
            'id_number_encrypted' => Crypt::encryptString($idNumber),
            'id_number_last4' => $last4,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'identity_registration_request' => [
                'name' => 'Jane Doe',
                'birth_year' => 1990,
                'client_category' => 'Regular',
                'id_type' => $idType,
                'id_number' => $idNumber,
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('unverified', true);

        $this->assertSame(1, IdentityRegistration::query()
            ->where('program_id', $program->id)
            ->where('status', 'pending')
            ->where('id_type', $idType)
            ->where('id_number_last4', $last4)
            ->count());

        $existing->refresh();
        $this->assertNotNull($existing->session_id);
    }

    public function test_public_bind_returns_409_when_client_already_queued(): void
    {
        ['track' => $track, 'program' => $program, 'station' => $station] = $this->createProgramWithTracks(true, [
            'identity_binding_mode' => 'optional',
        ]);

        $client = Client::factory()->create();
        $idDocument = ClientIdDocument::create([
            'client_id' => $client->id,
            'id_type' => 'PhilHealth',
            'id_number_encrypted' => encrypt('1234567890'),
            'id_number_hash' => 'hash',
        ]);

        $firstToken = $this->createToken('A1');
        $existingSession = Session::create([
            'token_id' => $firstToken->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'client_id' => $client->id,
            'client_category' => 'Regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'station_queue_position' => 1,
            'status' => 'waiting',
            'queued_at_station' => now(),
        ]);
        $firstToken->update(['status' => 'in_use', 'current_session_id' => $existingSession->id]);

        $secondToken = $this->createToken('B1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $secondToken->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
            'client_binding' => [
                'client_id' => $client->id,
                'source' => 'existing_id_document',
                'id_document_id' => $idDocument->id,
            ],
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'client_already_queued');
        $response->assertJsonPath('active_session.alias', 'A1');
        $this->assertDatabaseMissing('queue_sessions', [
            'token_id' => $secondToken->id,
        ]);
    }

    // --- A.2.3: Public triage program from URL (GET /public/triage/{program}) ---

    public function test_public_triage_page_returns_200_with_program_id_when_active_and_allow_public_triage(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(true);

        $response = $this->get('/public/triage/'.$program->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Triage/PublicStart')
            ->where('allowed', true)
            ->where('program_id', $program->id)
            ->has('program_name')
            ->has('tracks')
        );
    }

    public function test_public_triage_page_returns_404_for_inactive_program(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(true);
        $program->update(['is_active' => false]);

        $response = $this->get('/public/triage/'.$program->id);

        $response->assertStatus(404);
    }

    public function test_public_triage_page_returns_404_for_missing_program(): void
    {
        $response = $this->get('/public/triage/99999');

        $response->assertStatus(404);
    }

    public function test_public_triage_page_returns_200_with_allowed_false_when_allow_public_triage_false(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(false);

        $response = $this->get('/public/triage/'.$program->id);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Triage/PublicStart')
            ->where('allowed', false)
            ->where('program_id', $program->id)
        );
    }

    public function test_bind_with_program_id_sets_session_program_id(): void
    {
        ['program' => $program, 'track' => $track] = $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $program->id,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('queue_sessions', [
            'alias' => 'A1',
            'program_id' => $program->id,
        ]);
    }

    public function test_bind_without_program_id_returns_403_or_422(): void
    {
        ['track' => $track] = $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $this->assertContains($response->status(), [403, 422]);
    }

    public function test_bind_with_invalid_program_id_returns_403_or_422(): void
    {
        ['track' => $track] = $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
            'program_id' => 99999,
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $this->assertContains($response->status(), [403, 422]);
    }

    public function test_token_lookup_with_valid_program_id_returns_200_when_token_found(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->getJson('/api/public/token-lookup?qr_hash='.urlencode($token->qr_code_hash).'&program_id='.$program->id);

        $response->assertStatus(200);
        $response->assertJsonPath('physical_id', 'A1');
        $response->assertJsonPath('status', 'available');
    }

    public function test_token_lookup_with_invalid_program_id_returns_403(): void
    {
        $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->getJson('/api/public/token-lookup?qr_hash='.urlencode($token->qr_code_hash).'&program_id=99999');

        $response->assertStatus(403);
    }

    public function test_token_lookup_with_inactive_program_id_returns_403(): void
    {
        ['program' => $program] = $this->createProgramWithTracks(true);
        $program->update(['is_active' => false]);
        $token = $this->createToken('A1');

        $response = $this->getJson('/api/public/token-lookup?qr_hash='.urlencode($token->qr_code_hash).'&program_id='.$program->id);

        $response->assertStatus(403);
    }

    public function test_token_lookup_without_program_id_returns_403(): void
    {
        $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->getJson('/api/public/token-lookup?qr_hash='.urlencode($token->qr_code_hash));

        $response->assertStatus(403);
    }

    /** A.6.3: In multi-program setup, public triage binds sessions to the correct program_id. */
    public function test_public_triage_multi_program_bind_sessions_are_scoped_to_correct_programs(): void
    {
        ['program' => $programA, 'track' => $trackA] = $this->createProgramWithTracks(true, [
            'allow_unverified_entry' => true,
        ]);
        ['program' => $programB, 'track' => $trackB] = $this->createProgramWithTracks(true, [
            'allow_unverified_entry' => true,
        ]);

        $tokenA = $this->createToken('PA1');
        $tokenB = $this->createToken('PB1');

        // Bind in Program A
        $responseA = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $programA->id,
            'qr_hash' => $tokenA->qr_code_hash,
            'track_id' => $trackA->id,
            'client_category' => 'Regular',
        ]);
        $responseA->assertStatus(201);
        $aliasA = $responseA->json('session.alias');

        // Bind in Program B
        $responseB = $this->postJson('/api/public/sessions/bind', [
            'program_id' => $programB->id,
            'qr_hash' => $tokenB->qr_code_hash,
            'track_id' => $trackB->id,
            'client_category' => 'Regular',
        ]);
        $responseB->assertStatus(201);
        $aliasB = $responseB->json('session.alias');

        // Each session must be bound to its own program
        $this->assertDatabaseHas('queue_sessions', [
            'alias' => $aliasA,
            'program_id' => $programA->id,
        ]);
        $this->assertDatabaseHas('queue_sessions', [
            'alias' => $aliasB,
            'program_id' => $programB->id,
        ]);

        // Cross-contamination checks
        $this->assertDatabaseMissing('queue_sessions', [
            'alias' => $aliasA,
            'program_id' => $programB->id,
        ]);
        $this->assertDatabaseMissing('queue_sessions', [
            'alias' => $aliasB,
            'program_id' => $programA->id,
        ]);
    }
}
