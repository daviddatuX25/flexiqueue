<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\ClientIdDocument;
use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\TransactionLog;
use App\Models\User;
use App\Support\ClientIdNumberHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientIdentityApiTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['role' => 'staff']);
    }

    public function test_lookup_by_id_existing_returns_client_and_last4_only(): void
    {
        $client = Client::factory()->create();
        $raw = '12-3456-7890';
        $doc = ClientIdDocument::factory()->create([
            'client_id' => $client->id,
            'id_type' => 'PhilHealth',
            'id_number_encrypted' => Crypt::encryptString($raw),
            'id_number_hash' => ClientIdNumberHasher::hash('PhilHealth', $raw),
        ]);

        $response = $this->actingAs($this->staff)->postJson('/api/clients/lookup-by-id', [
            'id_type' => $doc->id_type,
            'id_number' => $raw,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('match_status', 'existing');
        $response->assertJsonPath('client.id', $client->id);
        $response->assertJsonPath('id_document.id', $doc->id);
        $response->assertJsonPath('id_document.id_last4', '7890');
        $this->assertArrayNotHasKey('id_number', $response->json('id_document') ?? []);
    }

    public function test_lookup_by_id_not_found_returns_not_found(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/clients/lookup-by-id', [
            'id_type' => 'PhilHealth',
            'id_number' => 'XX-0000-9999',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('match_status', 'not_found');
        $this->assertNull($response->json('client'));
    }

    public function test_create_client_with_id_document_persists_encrypted_and_rejects_duplicate_with_hint(): void
    {
        $existing = ClientIdDocument::factory()->create();
        $raw = Crypt::decryptString($existing->id_number_encrypted);

        $response = $this->actingAs($this->staff)->postJson('/api/clients', [
            'name' => 'Juan Dela Cruz',
            'birth_year' => 1985,
            'id_document' => [
                'id_type' => $existing->id_type,
                'id_number' => $raw,
            ],
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'id_document_duplicate');
        $this->assertStringContainsString('lookup-by-id', $response->json('hint') ?? '');
    }

    public function test_attach_id_document_duplicate_returns_409_with_error_code(): void
    {
        $client = Client::factory()->create();
        $existing = ClientIdDocument::factory()->create();
        $raw = Crypt::decryptString($existing->id_number_encrypted);

        $response = $this->actingAs($this->staff)->postJson("/api/clients/{$client->id}/id-documents", [
            'id_type' => $existing->id_type,
            'id_number' => $raw,
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'id_document_duplicate');
    }

    public function test_staff_bind_required_mode_missing_binding_returns_422_with_client_binding_error(): void
    {
        $staff = $this->staff;
        $program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $staff->id,
            'settings' => [
                'identity_binding_mode' => 'required',
            ],
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'First Station',
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
            'color_code' => '#333',
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'available';
        $token->save();

        $response = $this->actingAs($staff)->postJson('/api/sessions/bind', [
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'PWD',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.client_binding.0', 'Client identity binding is required for this program.');
        $this->assertDatabaseMissing('queue_sessions', ['alias' => 'A1']);
    }

    public function test_staff_bind_required_mode_with_binding_sets_client_id_and_writes_identity_bind_log(): void
    {
        $staff = $this->staff;
        $program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $staff->id,
            'settings' => [
                'identity_binding_mode' => 'required',
            ],
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'First Station',
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
            'color_code' => '#333',
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
        ]);
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'available';
        $token->save();

        $client = Client::factory()->create();
        $raw = '12-3456-7890';
        $doc = ClientIdDocument::factory()->create([
            'client_id' => $client->id,
            'id_type' => 'PhilHealth',
            'id_number_encrypted' => Crypt::encryptString($raw),
            'id_number_hash' => ClientIdNumberHasher::hash('PhilHealth', $raw),
        ]);

        $response = $this->actingAs($staff)->postJson('/api/sessions/bind', [
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'PWD',
            'client_binding' => [
                'client_id' => $client->id,
                'source' => 'existing_id_document',
                'id_document_id' => $doc->id,
            ],
        ]);

        $response->assertStatus(201);
        $sessionId = $response->json('session.id');
        $this->assertNotNull($sessionId);
        $this->assertDatabaseHas('queue_sessions', [
            'id' => $sessionId,
            'client_id' => $client->id,
        ]);

        /** @var TransactionLog|null $log */
        $log = TransactionLog::where('session_id', $sessionId)
            ->where('action_type', 'identity_bind')
            ->first();
        $this->assertNotNull($log);
        $metadata = $log->metadata;
        $this->assertSame($client->id, $metadata['client_id'] ?? null);
        $this->assertSame('required', $metadata['binding_mode'] ?? null);
        $this->assertSame('staff_triage', $metadata['binding_source'] ?? null);
        $this->assertSame('PhilHealth', $metadata['id_type'] ?? null);
        $this->assertSame('7890', $metadata['id_last4'] ?? null);
        $this->assertTrue($metadata['matched_existing_client'] ?? false);
        $this->assertArrayNotHasKey('id_number', $metadata);
    }

    public function test_admin_reveal_returns_raw_number_and_writes_audit_log_without_raw_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::factory()->create();
        $raw = '12-3456-7890';
        $doc = ClientIdDocument::factory()->create([
            'client_id' => $client->id,
            'id_type' => 'PhilHealth',
            'id_number_encrypted' => Crypt::encryptString($raw),
            'id_number_hash' => ClientIdNumberHasher::hash('PhilHealth', $raw),
        ]);

        $response = $this->actingAs($admin)->postJson("/api/admin/client-id-documents/{$doc->id}/reveal", [
            'confirm' => true,
            'reason' => 'Investigating possible duplicate registration',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('id_document.id', $doc->id);
        $response->assertJsonPath('id_document.client_id', $client->id);
        $response->assertJsonPath('id_document.id_type', 'PhilHealth');
        $this->assertSame($raw, $response->json('id_document.id_number'));

        $this->assertDatabaseHas('client_id_audit_log', [
            'client_id' => $client->id,
            'client_id_document_id' => $doc->id,
            'staff_user_id' => $admin->id,
            'action' => 'id_reveal',
            'id_type' => 'PhilHealth',
            'id_last4' => '7890',
        ]);
    }

    public function test_admin_reveal_missing_confirm_returns_422_with_errors_confirm(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::factory()->create();
        $doc = ClientIdDocument::factory()->create(['client_id' => $client->id]);

        $response = $this->actingAs($admin)->postJson("/api/admin/client-id-documents/{$doc->id}/reveal", [
            'confirm' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.confirm.0', 'Explicit confirmation is required to reveal this ID.');
    }

    public function test_supervisor_cannot_reveal_and_gets_403(): void
    {
        $supervisor = User::factory()->supervisor()->create();
        $client = Client::factory()->create();
        $doc = ClientIdDocument::factory()->create(['client_id' => $client->id]);

        $response = $this->actingAs($supervisor)->postJson("/api/admin/client-id-documents/{$doc->id}/reveal", [
            'confirm' => true,
            'reason' => 'Supervisor attempting reveal',
        ]);

        $response->assertStatus(403);
    }

}

