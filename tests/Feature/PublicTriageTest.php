<?php

namespace Tests\Feature;

use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\TransactionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Public self-serve triage: GET /triage/start, GET /api/public/token-lookup, POST /api/public/sessions/bind.
 * No auth. 403 when program allow_public_triage is false.
 */
class PublicTriageTest extends TestCase
{
    use RefreshDatabase;

    private function createProgramWithTracks(bool $allowPublicTriage = true): array
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => ['allow_public_triage' => $allowPublicTriage],
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
        $this->createProgramWithTracks(true);

        $response = $this->get('/triage/start');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Triage/PublicStart')
            ->where('allowed', true)
            ->has('program_name')
            ->has('tracks')
            ->has('date')
        );
        $props = $response->viewData('page')['props'];
        $this->assertSame('Test Program', $props['program_name']);
        $this->assertIsArray($props['tracks']);
        $this->assertCount(1, $props['tracks']);
        $this->assertSame('Default', $props['tracks'][0]['name']);
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
        $this->createProgramWithTracks(false);

        $response = $this->get('/triage/start');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Triage/PublicStart')
            ->where('allowed', false)
        );
    }

    public function test_public_token_lookup_returns_200_when_allowed_and_token_exists(): void
    {
        $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->getJson('/api/public/token-lookup?qr_hash='.urlencode($token->qr_code_hash));

        $response->assertStatus(200);
        $response->assertJsonPath('physical_id', 'A1');
        $response->assertJsonPath('qr_hash', $token->qr_code_hash);
        $response->assertJsonPath('status', 'available');
    }

    public function test_public_token_lookup_returns_403_when_public_triage_disabled(): void
    {
        $this->createProgramWithTracks(false);
        $token = $this->createToken('A1');

        $response = $this->getJson('/api/public/token-lookup?qr_hash='.urlencode($token->qr_code_hash));

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Public self-serve triage is not available.');
    }

    public function test_public_token_lookup_returns_404_when_token_not_found(): void
    {
        $this->createProgramWithTracks(true);

        $response = $this->getJson('/api/public/token-lookup?physical_id=Z99');

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Token not found.');
    }

    public function test_public_bind_creates_session_returns_201_when_allowed(): void
    {
        ['track' => $track] = $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
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
        ['track' => $track] = $this->createProgramWithTracks(false);
        $token = $this->createToken('A1');

        $response = $this->postJson('/api/public/sessions/bind', [
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
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Token is already in use.');
    }

    public function test_public_bind_transaction_log_has_null_staff_user_id(): void
    {
        ['track' => $track] = $this->createProgramWithTracks(true);
        $token = $this->createToken('A1');

        $this->postJson('/api/public/sessions/bind', [
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
        ]);

        $log = TransactionLog::where('action_type', 'bind')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->staff_user_id);
    }
}
