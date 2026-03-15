<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Site;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\TransactionLog;
use App\Models\User;
use App\Services\ClientService;
use App\Services\MobileCryptoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per PRIVACY-BY-DESIGN-IDENTITY-BINDING: phone-based client identity.
 */
class ClientIdentityApiTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create([
            'name' => 'Default',
            'slug' => 'default',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make('key'),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->staff = User::factory()->create(['role' => 'staff', 'site_id' => $this->site->id]);
    }

    public function test_search_by_phone_existing_returns_client_and_masked(): void
    {
        $mobileCrypto = app(MobileCryptoService::class);
        $clientService = app(ClientService::class);
        $client = $clientService->createClient('Juan', 'Cruz', '1985-01-01', $this->site->id, '09171234567', 'Dela');

        $response = $this->actingAs($this->staff)->postJson('/api/clients/search-by-phone', [
            'mobile' => '09171234567',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('match_status', 'existing');
        $response->assertJsonPath('client.id', $client->id);
        $response->assertJsonPath('client.mobile_masked', '0917-***-**67');
        $this->assertArrayNotHasKey('mobile', $response->json('client') ?? []);
    }

    public function test_search_by_phone_not_found_returns_not_found(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/clients/search-by-phone', [
            'mobile' => '09170000000',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('match_status', 'not_found');
        $this->assertNull($response->json('client'));
    }

    public function test_search_by_phone_normalizes_input(): void
    {
        $clientService = app(ClientService::class);
        $client = $clientService->createClient('Maria', 'Santos', '1990-01-01', $this->site->id, '09171234567');

        $response = $this->actingAs($this->staff)->postJson('/api/clients/search-by-phone', [
            'mobile' => '+639171234567',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('match_status', 'existing');
        $response->assertJsonPath('client.id', $client->id);
    }

    public function test_create_client_with_mobile_persists_and_returns_masked(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/clients', [
            'first_name' => 'Juan',
            'middle_name' => 'Dela',
            'last_name' => 'Cruz',
            'birth_date' => '1985-01-01',
            'mobile' => '09171234567',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('clients', ['first_name' => 'Juan', 'last_name' => 'Cruz']);
        $client = Client::where('first_name', 'Juan')->where('last_name', 'Cruz')->first();
        $this->assertNotNull($client->mobile_hash);
        $this->assertNotNull($client->mobile_encrypted);
        $response->assertJsonPath('client.mobile_masked', '0917-***-**67');
    }

    public function test_staff_bind_required_mode_missing_binding_returns_422_with_client_binding_error(): void
    {
        $staff = $this->staff;
        $program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $staff->id,
            'site_id' => $this->site->id,
            'settings' => ['identity_binding_mode' => 'required'],
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'First Station',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $staff->update(['assigned_station_id' => $station->id]);
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
        $token->site_id = $this->site->id;
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

    public function test_staff_bind_required_mode_with_phone_match_sets_client_id_and_writes_identity_bind_log(): void
    {
        $staff = $this->staff;
        $program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $staff->id,
            'site_id' => $this->site->id,
            'settings' => ['identity_binding_mode' => 'required'],
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'First Station',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $staff->update(['assigned_station_id' => $station->id]);
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
        $token->site_id = $this->site->id;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'available';
        $token->save();

        $clientService = app(ClientService::class);
        $client = $clientService->createClient('Juan', 'Cruz', '1985-01-01', $this->site->id, '09171234567');

        $response = $this->actingAs($staff)->postJson('/api/sessions/bind', [
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'PWD',
            'client_binding' => [
                'client_id' => $client->id,
                'source' => 'phone_match',
            ],
        ]);

        $response->assertStatus(201);
        $sessionId = $response->json('session.id');
        $this->assertNotNull($sessionId);
        $this->assertDatabaseHas('queue_sessions', [
            'id' => $sessionId,
            'client_id' => $client->id,
        ]);

        $log = TransactionLog::where('session_id', $sessionId)
            ->where('action_type', 'identity_bind')
            ->first();
        $this->assertNotNull($log);
        $metadata = $log->metadata;
        $this->assertSame($client->id, $metadata['client_id'] ?? null);
        $this->assertSame('required', $metadata['binding_mode'] ?? null);
        $this->assertSame('staff_triage', $metadata['binding_source'] ?? null);
        $this->assertSame('phone_match', $metadata['binding_request_source'] ?? null);
    }

    public function test_staff_bind_with_name_search_source_succeeds_without_id_document(): void
    {
        $staff = $this->staff;
        $program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $staff->id,
            'site_id' => $this->site->id,
            'settings' => ['identity_binding_mode' => 'required'],
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'First Station',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $staff->update(['assigned_station_id' => $station->id]);
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
        $token->site_id = $this->site->id;
        $token->qr_code_hash = hash('sha256', Str::random(32).'B2');
        $token->physical_id = 'B2';
        $token->status = 'available';
        $token->save();
        $client = Client::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'birth_date' => '1990-01-01', 'site_id' => $this->site->id]);

        $response = $this->actingAs($staff)->postJson('/api/sessions/bind', [
            'qr_hash' => $token->qr_code_hash,
            'track_id' => $track->id,
            'client_category' => 'Regular',
            'client_binding' => [
                'client_id' => $client->id,
                'source' => 'name_search',
            ],
        ]);

        $response->assertStatus(201);
        $sessionId = $response->json('session.id');
        $this->assertNotNull($sessionId);
        $this->assertDatabaseHas('queue_sessions', [
            'id' => $sessionId,
            'client_id' => $client->id,
        ]);
        $log = TransactionLog::where('session_id', $sessionId)->where('action_type', 'identity_bind')->first();
        $this->assertNotNull($log);
        $metadata = $log->metadata;
        $this->assertSame($client->id, $metadata['client_id'] ?? null);
        $this->assertSame('name_search', $metadata['binding_request_source'] ?? null);
    }

    public function test_admin_reveal_phone_returns_raw_and_writes_audit_log(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'site_id' => $this->site->id]);
        $clientService = app(ClientService::class);
        $client = $clientService->createClient('Juan', 'Cruz', '1985-01-01', $this->site->id, '09171234567');

        $response = $this->actingAs($admin)->postJson("/api/clients/{$client->id}/reveal-phone", [
            'reason' => 'Investigating possible duplicate registration',
            'confirm' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('mobile', '09171234567');

        $this->assertDatabaseHas('client_id_audit_log', [
            'client_id' => $client->id,
            'staff_user_id' => $admin->id,
            'action' => 'phone_reveal',
            'mobile_last2' => '67',
        ]);
    }

    public function test_admin_reveal_phone_missing_confirm_returns_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'site_id' => $this->site->id]);
        $clientService = app(ClientService::class);
        $client = $clientService->createClient('Juan', 'Cruz', '1985-01-01', $this->site->id, '09171234567');

        $response = $this->actingAs($admin)->postJson("/api/clients/{$client->id}/reveal-phone", [
            'reason' => 'Test',
            'confirm' => false,
        ]);

        $response->assertStatus(422);
    }

    public function test_supervisor_cannot_reveal_phone_and_gets_403(): void
    {
        $supervisor = User::factory()->supervisor()->create(['site_id' => $this->site->id]);
        $clientService = app(ClientService::class);
        $client = $clientService->createClient('Juan', 'Cruz', '1985-01-01', $this->site->id, '09171234567');

        $response = $this->actingAs($supervisor)->postJson("/api/clients/{$client->id}/reveal-phone", [
            'reason' => 'Supervisor attempting reveal',
            'confirm' => true,
        ]);

        $response->assertStatus(403);
    }

    public function test_client_search_returns_200_with_data_and_meta(): void
    {
        Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos', 'birth_date' => '1990-01-01', 'site_id' => $this->site->id]);
        Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Clara', 'birth_date' => '1985-01-01', 'site_id' => $this->site->id]);

        $response = $this->actingAs($this->staff)->getJson('/api/clients/search?name=Maria');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total', 'per_page']]);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertArrayHasKey('mobile_masked', $data[0]);
        $this->assertSame(1, $response->json('meta.current_page'));
        $this->assertSame(3, $response->json('meta.per_page'));
    }

    public function test_client_search_pagination_returns_per_page_results(): void
    {
        Client::factory()->create(['first_name' => 'Alpha', 'last_name' => 'One', 'birth_date' => '1990-01-01', 'site_id' => $this->site->id]);
        Client::factory()->create(['first_name' => 'Alpha', 'last_name' => 'Two', 'birth_date' => '1990-01-01', 'site_id' => $this->site->id]);
        Client::factory()->create(['first_name' => 'Alpha', 'last_name' => 'Three', 'birth_date' => '1990-01-01', 'site_id' => $this->site->id]);
        Client::factory()->create(['first_name' => 'Alpha', 'last_name' => 'Four', 'birth_date' => '1990-01-01', 'site_id' => $this->site->id]);

        $response = $this->actingAs($this->staff)->getJson('/api/clients/search?name=Alpha&per_page=3&page=1');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
        $this->assertSame(2, $response->json('meta.last_page'));
        $this->assertSame(4, $response->json('meta.total'));
    }

    public function test_client_search_with_birth_date_filters(): void
    {
        Client::factory()->create(['first_name' => 'Juan', 'last_name' => 'Perez', 'birth_date' => '1980-01-01', 'site_id' => $this->site->id]);
        Client::factory()->create(['first_name' => 'Juan', 'last_name' => 'Cruz', 'birth_date' => '1990-01-01', 'site_id' => $this->site->id]);

        $response = $this->actingAs($this->staff)->getJson('/api/clients/search?name=Juan&birth_date=1980-01-01');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('1980-01-01', $data[0]['birth_date']);
    }

    public function test_client_search_tokenizes_name_and_matches_all_tokens(): void
    {
        Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos', 'birth_date' => '1990-01-01', 'site_id' => $this->site->id]);
        Client::factory()->create(['first_name' => 'Santos', 'last_name' => 'Maria', 'birth_date' => '1985-01-01', 'site_id' => $this->site->id]);
        Client::factory()->create(['first_name' => 'Maria', 'last_name' => 'Clara', 'birth_date' => '1988-01-01', 'site_id' => $this->site->id]);

        $response = $this->actingAs($this->staff)->getJson('/api/clients/search?name=Maria Santos');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $lastNames = array_column($data, 'last_name');
        $this->assertContains('Santos', $lastNames);
        $this->assertContains('Maria', $lastNames);
        $this->assertNotContains('Clara', $lastNames);
    }

    public function test_client_search_name_required_returns_422(): void
    {
        $response = $this->actingAs($this->staff)->getJson('/api/clients/search');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_client_search_unauthenticated_redirects_or_401(): void
    {
        $response = $this->getJson('/api/clients/search?name=Test');

        $this->assertTrue(in_array($response->status(), [301, 302, 401], true));
    }
}
