<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\IdentityRegistration;
use App\Models\Process;
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
            'first_name' => 'Direct',
            'last_name' => 'Client',
            'birth_date' => '1995-01-01',
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Registration created.');
        $this->assertDatabaseHas('clients', ['first_name' => 'Direct', 'last_name' => 'Client']);
        $reg = IdentityRegistration::where('program_id', $program->id)->where('status', 'accepted')->first();
        $this->assertNotNull($reg);
        $this->assertSame('Direct', $reg->first_name);
        $this->assertSame('Client', $reg->last_name);
        $this->assertSame('1995-01-01', $reg->birth_date?->format('Y-m-d'));
        $this->assertNotNull($reg->client_id);
        $this->assertNotNull($reg->resolved_at);
        $this->assertSame($staff->id, $reg->resolved_by_user_id);
    }

    public function test_direct_with_mobile_creates_client_with_mobile(): void
    {
        ['program' => $program, 'station' => $station] = $this->createProgramWithTrack();
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->postJson('/api/identity-registrations/direct', [
            'first_name' => 'With',
            'last_name' => 'Mobile',
            'birth_date' => '1980-01-01',
            'client_category' => 'PWD / Senior / Pregnant',
            'mobile' => '09171234567',
        ]);

        $response->assertStatus(200);
        $reg = IdentityRegistration::where('program_id', $program->id)->where('status', 'accepted')->first();
        $this->assertNotNull($reg);
        $client = Client::find($reg->client_id);
        $this->assertSame('With', $client->first_name);
        $this->assertSame('Mobile', $client->last_name);
        $this->assertNotNull($client->mobile_hash);
        $this->assertNotNull($client->mobile_encrypted);
    }

    public function test_direct_returns_422_when_name_or_birth_year_missing(): void
    {
        ['program' => $program, 'station' => $station] = $this->createProgramWithTrack();
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->postJson('/api/identity-registrations/direct', [
            'first_name' => '',
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
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
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
        $this->assertSame('Jane', $data[0]['first_name']);
        $this->assertSame('Doe', $data[0]['last_name']);
        $this->assertSame('1990-01-01', $data[0]['birth_date']);
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
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $session->update(['identity_registration_id' => $reg->id]);
        $client = Client::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'birth_date' => '1990-01-01']);
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->postJson("/api/identity-registrations/{$reg->id}/accept", [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
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
            'first_name' => 'New',
            'last_name' => 'Person',
            'birth_date' => '1985-01-01',
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $session->update(['identity_registration_id' => $reg->id]);
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->postJson("/api/identity-registrations/{$reg->id}/accept", [
            'first_name' => 'New',
            'last_name' => 'Person',
            'birth_date' => '1985-01-01',
            'client_category' => 'Regular',
            'create_new_client' => true,
        ]);

        $response->assertStatus(200);
        $reg->refresh();
        $this->assertSame('accepted', $reg->status);
        $this->assertNotNull($reg->client_id);
        $client = Client::find($reg->client_id);
        $this->assertSame('New', $client->first_name);
        $this->assertSame('Person', $client->last_name);
        $this->assertSame('1985-01-01', $client->birth_date?->format('Y-m-d'));
    }

    public function test_reject_updates_status(): void
    {
        ['program' => $program, 'station' => $station] = $this->createProgramWithTrack();
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'first_name' => 'Jane',
            'last_name' => null,
            'birth_date' => null,
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
            'first_name' => 'Direct',
            'last_name' => 'Client',
            'birth_date' => '1995-01-01',
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
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
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
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => $station->id]);

        $response = $this->actingAs($staff)->getJson("/api/identity-registrations/{$reg->id}/possible-matches");

        $response->assertStatus(200);
        $response->assertJsonPath('data', []);
    }

    public function test_accept_returns_422_when_staff_has_no_assigned_station(): void
    {
        ['program' => $program] = $this->createProgramWithTrack();
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $client = Client::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'birth_date' => '1990-01-01']);
        $staff = User::factory()->create(['role' => 'staff', 'assigned_station_id' => null]);

        $response = $this->actingAs($staff)->postJson("/api/identity-registrations/{$reg->id}/accept", [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
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
            'first_name' => 'Jane',
            'last_name' => null,
            'birth_date' => null,
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
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
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
            'first_name' => 'Direct',
            'last_name' => 'Admin Client',
            'birth_date' => '1992-01-01',
            'client_category' => 'Regular',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('identity_registrations', [
            'program_id' => $program->id,
            'first_name' => 'Direct',
            'last_name' => 'Admin Client',
            'status' => 'accepted',
        ]);
    }

    public function test_possible_matches_returns_200_for_admin_without_station_using_registration_program(): void
    {
        ['program' => $program] = $this->createProgramWithTrack();
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'assigned_station_id' => null]);

        $response = $this->actingAs($admin)->getJson("/api/identity-registrations/{$reg->id}/possible-matches");

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }

    public function test_accept_returns_200_for_admin_without_station_for_pending_registration(): void
    {
        ['program' => $program] = $this->createProgramWithTrack();
        $reg = IdentityRegistration::create([
            'program_id' => $program->id,
            'session_id' => null,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
            'client_category' => 'Regular',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
        $client = Client::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'birth_date' => '1990-01-01']);
        $admin = User::factory()->create(['role' => 'admin', 'assigned_station_id' => null]);

        $response = $this->actingAs($admin)->postJson("/api/identity-registrations/{$reg->id}/accept", [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
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
            'first_name' => 'Jane',
            'last_name' => null,
            'birth_date' => null,
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
