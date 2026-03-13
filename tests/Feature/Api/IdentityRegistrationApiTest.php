<?php

namespace Tests\Feature\Api;

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
use App\Models\User;
use App\Support\ClientIdNumberHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdentityRegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    private function createProgramWithTrack(): array
    {
        $user = User::factory()->create();
        $program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'settings' => [],
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

        return ['program' => $program, 'track' => $track, 'station' => $station];
    }

    public function test_direct_creates_accepted_registration_and_client(): void
    {
        ['program' => $program, 'station' => $station] = $this->createProgramWithTrack();
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->postJson('/api/identity-registrations/direct', [
            'name' => 'Direct Client',
            'birth_year' => 1995,
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Registration created.');
        $this->assertDatabaseHas('clients', ['name' => 'Direct Client', 'birth_year' => 1995]);
        $reg = IdentityRegistration::where('program_id', $program->id)->where('status', 'accepted')->first();
        $this->assertNotNull($reg);
        $this->assertSame('Direct Client', $reg->name);
        $this->assertSame(1995, $reg->birth_year);
        $this->assertNotNull($reg->client_id);
        $this->assertNotNull($reg->resolved_at);
        $this->assertSame($staff->id, $reg->resolved_by_user_id);
    }

    public function test_direct_with_id_type_and_number_creates_client_id_document(): void
    {
        ['program' => $program, 'station' => $station] = $this->createProgramWithTrack();
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->postJson('/api/identity-registrations/direct', [
            'name' => 'With ID',
            'birth_year' => 1980,
            'client_category' => 'PWD / Senior / Pregnant',
            'id_type' => 'PhilHealth',
            'id_number' => '12-3456-7890',
        ]);

        $response->assertStatus(200);
        $reg = IdentityRegistration::where('program_id', $program->id)->where('status', 'accepted')->first();
        $this->assertNotNull($reg);
        $client = Client::find($reg->client_id);
        $this->assertSame('With ID', $client->name);
        $doc = ClientIdDocument::where('client_id', $client->id)->first();
        $this->assertNotNull($doc);
        $this->assertSame('PhilHealth', $doc->id_type);
    }

    public function test_direct_returns_422_when_name_or_birth_year_missing(): void
    {
        ['program' => $program, 'station' => $station] = $this->createProgramWithTrack();
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->postJson('/api/identity-registrations/direct', [
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(422);
    }

    public function test_index_returns_pending_registrations_for_active_program(): void
    {
        ['program' => $program, 'station' => $station] = $this->createProgramWithTrack();
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'name' => 'Jane Doe',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->getJson('/api/identity-registrations');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame($reg->id, $data[0]['id']);
        $this->assertSame('Jane Doe', $data[0]['name']);
        $this->assertSame(1990, $data[0]['birth_year']);
    }

    public function test_accept_updates_registration_and_session_and_links_client(): void
    {
        ['program' => $program, 'track' => $track, 'station' => $station] = $this->createProgramWithTrack();
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'client_id' => null,
            'identity_registration_id' => null,
            'client_category' => 'Regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'station_queue_position' => 1,
            'status' => 'waiting',
            'queued_at_station' => now(),
        ]);
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => $session->id,
            'name' => 'Jane Doe',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $session->update(['identity_registration_id' => $reg->id]);
        $client = Client::factory()->create(['name' => 'Jane Doe', 'birth_year' => 1990]);
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->postJson("/api/identity-registrations/{$reg->id}/accept", [
            'name' => 'Jane Doe',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'client_id' => $client->id,
        ]);

        $response->assertStatus(200);
        $reg->refresh();
        $this->assertSame('accepted', $reg->status);
        $this->assertSame($client->id, $reg->client_id);
        $session->refresh();
        $this->assertSame($client->id, $session->client_id);
    }

    public function test_accept_create_new_client_and_optional_register_id(): void
    {
        ['program' => $program, 'track' => $track, 'station' => $station] = $this->createProgramWithTrack();
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'B1');
        $token->physical_id = 'B1';
        $token->status = 'in_use';
        $token->save();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'B1',
            'client_id' => null,
            'identity_registration_id' => null,
            'client_category' => 'Regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'station_queue_position' => 1,
            'status' => 'waiting',
            'queued_at_station' => now(),
        ]);
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => $session->id,
            'name' => 'New Person',
            'birth_year' => 1985,
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $session->update(['identity_registration_id' => $reg->id]);
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->postJson("/api/identity-registrations/{$reg->id}/accept", [
            'name' => 'New Person',
            'birth_year' => 1985,
            'client_category' => 'Regular',
            'create_new_client' => true,
            'register_id' => [
                'id_type' => 'PhilHealth',
                'id_number' => '12-3456-7890',
            ],
        ]);

        $response->assertStatus(200);
        $reg->refresh();
        $this->assertSame('accepted', $reg->status);
        $this->assertNotNull($reg->client_id);
        $client = Client::find($reg->client_id);
        $this->assertSame('New Person', $client->name);
        $this->assertSame(1985, $client->birth_year);
        $doc = ClientIdDocument::where('client_id', $client->id)->first();
        $this->assertNotNull($doc);
        $this->assertSame('PhilHealth', $doc->id_type);
    }

    public function test_reject_updates_status(): void
    {
        ['program' => $program, 'station' => $station] = $this->createProgramWithTrack();
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'name' => 'Jane',
            'birth_year' => null,
            'client_category' => null,
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->postJson("/api/identity-registrations/{$reg->id}/reject", []);

        $response->assertStatus(200);
        $reg->refresh();
        $this->assertSame('rejected', $reg->status);
        $this->assertNotNull($reg->resolved_at);
    }

    public function test_verify_id_sets_verified_when_scanned_matches_stored(): void
    {
        ['program' => $program, 'station' => $station] = $this->createProgramWithTrack();
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'name' => 'Jane',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'id_type' => 'PhilHealth',
            'id_number_encrypted' => Crypt::encryptString('12-3456-7890'),
            'id_number_last4' => '7890',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->postJson("/api/identity-registrations/{$reg->id}/verify-id", [
            'id_type' => 'PhilHealth',
            'id_number' => '1234567890',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('verified', true);
        $response->assertJsonPath('id_verified_by_user_id', $staff->id);
        $reg->refresh();
        $this->assertNotNull($reg->id_verified_at);
        $this->assertSame($staff->id, $reg->id_verified_by_user_id);
    }

    public function test_verify_id_returns_409_when_id_already_registered(): void
    {
        ['program' => $program, 'station' => $station] = $this->createProgramWithTrack();
        $existingClient = Client::factory()->create(['name' => 'Existing', 'birth_year' => 1990]);
        ClientIdDocument::create([
            'client_id' => $existingClient->id,
            'id_type' => 'PhilHealth',
            'id_number_encrypted' => Crypt::encryptString('12-3456-7890'),
            'id_number_hash' => ClientIdNumberHasher::hash('PhilHealth', '12-3456-7890'),
        ]);

        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'name' => 'Jane',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'id_type' => 'PhilHealth',
            'id_number_encrypted' => Crypt::encryptString('12-3456-7890'),
            'id_number_last4' => '7890',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->postJson("/api/identity-registrations/{$reg->id}/verify-id", [
            'id_type' => 'PhilHealth',
            'id_number' => '1234567890',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'This ID number is already registered to another client.');
    }

    public function test_verify_id_returns_422_when_no_stored_id(): void
    {
        ['program' => $program, 'station' => $station] = $this->createProgramWithTrack();
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'name' => 'Jane',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'id_type' => null,
            'id_number_encrypted' => null,
            'id_number_last4' => null,
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->postJson("/api/identity-registrations/{$reg->id}/verify-id", [
            'id_number' => '1234567890',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'No ID to verify.');
    }

    public function test_accept_with_stored_id_ignores_register_id(): void
    {
        ['program' => $program, 'track' => $track, 'station' => $station] = $this->createProgramWithTrack();
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'C1');
        $token->physical_id = 'C1';
        $token->status = 'in_use';
        $token->save();
        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'C1',
            'client_id' => null,
            'identity_registration_id' => null,
            'client_category' => 'Regular',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'station_queue_position' => 1,
            'status' => 'waiting',
            'queued_at_station' => now(),
        ]);
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => $session->id,
            'name' => 'Has Stored ID',
            'birth_year' => 1988,
            'client_category' => 'Regular',
            'id_type' => 'PhilHealth',
            'id_number_encrypted' => Crypt::encryptString('98-7654-3210'),
            'id_number_last4' => '3210',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $session->update(['identity_registration_id' => $reg->id]);
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->postJson("/api/identity-registrations/{$reg->id}/accept", [
            'name' => 'Has Stored ID',
            'birth_year' => 1988,
            'client_category' => 'Regular',
            'create_new_client' => true,
            'register_id' => [
                'id_type' => 'PhilHealth',
                'id_number' => '11-2222-3333',
            ],
        ]);

        $response->assertStatus(200);
        $reg->refresh();
        $this->assertSame('accepted', $reg->status);
        $client = Client::find($reg->client_id);
        $this->assertNotNull($client);
        $this->assertSame('Has Stored ID', $client->name);
        $docCount = ClientIdDocument::where('client_id', $client->id)->count();
        $this->assertSame(0, $docCount, 'register_id must be ignored when registration has stored ID');
    }

    public function test_accept_with_verified_stored_id_attaches_to_existing_client(): void
    {
        ['program' => $program, 'station' => $station] = $this->createProgramWithTrack();
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);
        $client = Client::factory()->create(['name' => 'Target', 'birth_year' => 1999]);

        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'name' => 'Reg Name',
            'birth_year' => 2000,
            'client_category' => 'Regular',
            'id_type' => 'PhilHealth',
            'id_number_encrypted' => Crypt::encryptString('98-7654-3210'),
            'id_number_last4' => '3210',
            'id_verified_at' => now(),
            'id_verified_by_user_id' => $staff->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $response = $this->actingAs($staff)->postJson("/api/identity-registrations/{$reg->id}/accept", [
            'name' => $client->name,
            'birth_year' => $client->birth_year,
            'client_category' => 'Regular',
            'client_id' => $client->id,
        ]);

        $response->assertStatus(200);
        $doc = ClientIdDocument::where('client_id', $client->id)->first();
        $this->assertNotNull($doc);
        $this->assertSame('PhilHealth', $doc->id_type);
    }

    // --- A.2.2 Task B: staff must have assigned station; 422 "No station assigned." when null ---

    public function test_index_returns_422_when_staff_has_no_assigned_station(): void
    {
        $this->createProgramWithTrack();
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => null]);

        $response = $this->actingAs($staff)->getJson('/api/identity-registrations');

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'No station assigned.');
    }

    public function test_direct_returns_422_when_staff_has_no_assigned_station(): void
    {
        $this->createProgramWithTrack();
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => null]);

        $response = $this->actingAs($staff)->postJson('/api/identity-registrations/direct', [
            'name' => 'Direct Client',
            'birth_year' => 1995,
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'No station assigned.');
    }

    public function test_possible_matches_returns_422_when_staff_has_no_assigned_station(): void
    {
        ['program' => $program] = $this->createProgramWithTrack();
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'name' => 'Jane Doe',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => null]);

        $response = $this->actingAs($staff)->getJson("/api/identity-registrations/{$reg->id}/possible-matches");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'No station assigned.');
    }

    public function test_possible_matches_returns_data_when_staff_has_assigned_station(): void
    {
        ['program' => $program, 'station' => $station] = $this->createProgramWithTrack();
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'name' => 'Jane Doe',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->getJson("/api/identity-registrations/{$reg->id}/possible-matches");

        $response->assertStatus(200);
        $response->assertJsonPath('data', []);
    }

    public function test_verify_id_returns_422_when_staff_has_no_assigned_station(): void
    {
        ['program' => $program] = $this->createProgramWithTrack();
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'name' => 'Jane',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'id_type' => 'PhilHealth',
            'id_number_encrypted' => Crypt::encryptString('12-3456-7890'),
            'id_number_last4' => '7890',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => null]);

        $response = $this->actingAs($staff)->postJson("/api/identity-registrations/{$reg->id}/verify-id", [
            'id_type' => 'PhilHealth',
            'id_number' => '1234567890',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'No station assigned.');
    }

    public function test_accept_returns_422_when_staff_has_no_assigned_station(): void
    {
        ['program' => $program] = $this->createProgramWithTrack();
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'name' => 'Jane Doe',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $client = Client::factory()->create(['name' => 'Jane Doe', 'birth_year' => 1990]);
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => null]);

        $response = $this->actingAs($staff)->postJson("/api/identity-registrations/{$reg->id}/accept", [
            'name' => 'Jane Doe',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'client_id' => $client->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'No station assigned.');
    }

    public function test_reject_returns_422_when_staff_has_no_assigned_station(): void
    {
        ['program' => $program] = $this->createProgramWithTrack();
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'name' => 'Jane',
            'birth_year' => null,
            'client_category' => null,
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => null]);

        $response = $this->actingAs($staff)->postJson("/api/identity-registrations/{$reg->id}/reject", []);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'No station assigned.');
    }

    // --- Central-edge follow-up: admin/supervisor with no assigned station uses session/request program context ---

    public function test_index_returns_200_for_admin_without_station_when_session_has_program(): void
    {
        ['program' => $program] = $this->createProgramWithTrack();
        IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'name' => 'Jane Doe',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'assigned_station_id' => null]);

        $response = $this
            ->withSession([\App\Http\Controllers\StationPageController::SESSION_KEY_PROGRAM_ID => $program->id])
            ->actingAs($admin)
            ->getJson('/api/identity-registrations');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }

    public function test_direct_returns_200_for_admin_without_station_when_program_id_in_body(): void
    {
        ['program' => $program] = $this->createProgramWithTrack();
        $admin = User::factory()->create(['role' => 'admin', 'assigned_station_id' => null]);

        $response = $this->actingAs($admin)->postJson('/api/identity-registrations/direct', [
            'program_id' => $program->id,
            'name' => 'Direct Admin Client',
            'birth_year' => 1992,
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('identity_registrations', [
            'program_id' => $program->id,
            'name' => 'Direct Admin Client',
            'status' => 'accepted',
        ]);
    }

    public function test_possible_matches_returns_200_for_admin_without_station_using_registration_program(): void
    {
        ['program' => $program] = $this->createProgramWithTrack();
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'name' => 'Jane Doe',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'assigned_station_id' => null]);

        $response = $this->actingAs($admin)->getJson("/api/identity-registrations/{$reg->id}/possible-matches");

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }

    public function test_verify_id_returns_200_for_admin_without_station_when_id_matches(): void
    {
        ['program' => $program] = $this->createProgramWithTrack();
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'name' => 'Jane',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'id_type' => 'PhilHealth',
            'id_number_encrypted' => Crypt::encryptString('12-3456-7890'),
            'id_number_last4' => '7890',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'assigned_station_id' => null]);

        $response = $this->actingAs($admin)->postJson("/api/identity-registrations/{$reg->id}/verify-id", [
            'id_type' => 'PhilHealth',
            'id_number' => '12-3456-7890',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('verified', true);
    }

    public function test_accept_returns_200_for_admin_without_station_for_pending_registration(): void
    {
        ['program' => $program] = $this->createProgramWithTrack();
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'name' => 'Jane Doe',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $client = Client::factory()->create(['name' => 'Jane Doe', 'birth_year' => 1990]);
        $admin = User::factory()->create(['role' => 'admin', 'assigned_station_id' => null]);

        $response = $this->actingAs($admin)->postJson("/api/identity-registrations/{$reg->id}/accept", [
            'name' => 'Jane Doe',
            'birth_year' => 1990,
            'client_category' => 'Regular',
            'client_id' => $client->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('identity_registrations', ['id' => $reg->id, 'status' => 'accepted']);
    }

    public function test_reject_returns_200_for_admin_without_station_for_pending_registration(): void
    {
        ['program' => $program] = $this->createProgramWithTrack();
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'name' => 'Jane',
            'birth_year' => null,
            'client_category' => null,
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'assigned_station_id' => null]);

        $response = $this->actingAs($admin)->postJson("/api/identity-registrations/{$reg->id}/reject", []);

        $response->assertStatus(200);
        $this->assertDatabaseHas('identity_registrations', ['id' => $reg->id, 'status' => 'rejected']);
    }
}
