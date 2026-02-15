<?php

namespace Tests\Feature\Api;

use App\Models\Program;
use App\Models\Session;
use App\Models\Station;
use App\Models\TemporaryAuthorization;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per docs/plans/PIN-QR-AUTHORIZATION-SYSTEM.md AUTH-4: Temporary QR.
 */
class TemporaryQrTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private Program $program;

    private Station $station1;

    private Station $station2;

    private \App\Models\ServiceTrack $track;

    private \App\Models\ServiceTrack $trackToStation2;

    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supervisor = User::factory()->supervisor()->withOverridePin('123456')->create();
        $staff = User::factory()->create(['role' => 'staff']);
        $this->program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => true,
            'created_by' => $staff->id,
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
        $this->track = \App\Models\ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        TrackStep::create(['track_id' => $this->track->id, 'station_id' => $this->station1->id, 'step_order' => 1, 'is_required' => true]);
        TrackStep::create(['track_id' => $this->track->id, 'station_id' => $this->station2->id, 'step_order' => 2, 'is_required' => true]);
        $this->trackToStation2 = \App\Models\ServiceTrack::create([
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

    public function test_temporary_qr_generate_returns_qr_data_uri(): void
    {
        $response = $this->actingAs($this->supervisor)->postJson('/api/auth/temporary-qr', [
            'expires_in_seconds' => 300,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['qr_data_uri', 'expires_at', 'expires_in_seconds']);
        $this->assertStringStartsWith('data:image/png;base64,', $response->json('qr_data_uri'));
        $this->assertDatabaseCount('temporary_authorizations', 1);
        $this->assertDatabaseHas('temporary_authorizations', [
            'user_id' => $this->supervisor->id,
            'type' => 'qr',
        ]);
    }

    public function test_override_with_temp_qr_returns_200(): void
    {
        $scanToken = Str::random(64);
        TemporaryAuthorization::create([
            'user_id' => $this->supervisor->id,
            'token_hash' => Hash::make($scanToken),
            'type' => 'qr',
            'expires_at' => now()->addMinutes(5),
        ]);

        $staff = User::factory()->create(['role' => 'staff']);
        $response = $this->actingAs($staff)->postJson("/api/sessions/{$this->session->id}/override", [
            'target_track_id' => $this->trackToStation2->id,
            'reason' => 'Skip step',
            'auth_type' => 'temp_qr',
            'qr_scan_token' => $scanToken,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'override']);
    }

    public function test_override_with_used_temp_qr_returns_401(): void
    {
        $scanToken = Str::random(64);
        TemporaryAuthorization::create([
            'user_id' => $this->supervisor->id,
            'token_hash' => Hash::make($scanToken),
            'type' => 'qr',
            'expires_at' => now()->addMinutes(5),
        ]);

        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->postJson("/api/sessions/{$this->session->id}/override", [
            'target_track_id' => $this->trackToStation2->id,
            'reason' => 'First use',
            'auth_type' => 'temp_qr',
            'qr_scan_token' => $scanToken,
        ])->assertStatus(200);

        $response = $this->actingAs($staff)->postJson("/api/sessions/{$this->session->id}/override", [
            'target_track_id' => $this->track->id,
            'reason' => 'Replay',
            'auth_type' => 'temp_qr',
            'qr_scan_token' => $scanToken,
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Authorization expired. Request a new one.');
    }

    public function test_force_complete_with_temp_qr_returns_200(): void
    {
        $scanToken = Str::random(64);
        TemporaryAuthorization::create([
            'user_id' => $this->supervisor->id,
            'token_hash' => Hash::make($scanToken),
            'type' => 'qr',
            'expires_at' => now()->addMinutes(5),
        ]);

        $staff = User::factory()->create(['role' => 'staff']);
        $response = $this->actingAs($staff)->postJson("/api/sessions/{$this->session->id}/force-complete", [
            'reason' => 'Client left',
            'auth_type' => 'temp_qr',
            'qr_scan_token' => $scanToken,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('session.status', 'completed');
        $this->assertDatabaseHas('transaction_logs', ['session_id' => $this->session->id, 'action_type' => 'force_complete']);
    }

    public function test_temporary_qr_staff_cannot_generate(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($staff)->postJson('/api/auth/temporary-qr');

        $response->assertStatus(403);
    }
}
