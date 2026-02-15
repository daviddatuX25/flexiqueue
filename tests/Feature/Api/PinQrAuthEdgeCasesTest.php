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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per docs/plans/PIN-QR-AUTHORIZATION-SYSTEM.md AUTH-6: Edge cases.
 */
class PinQrAuthEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private Program $program;

    private Station $station1;

    private Station $station2;

    private \App\Models\ServiceTrack $trackToStation2;

    private Session $session;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supervisor = User::factory()->supervisor()->withOverridePin('123456')->create();
        $this->staff = User::factory()->create(['role' => 'staff']);
        $this->program = Program::create([
            'name' => 'Test',
            'description' => null,
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
        $track = \App\Models\ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);
        TrackStep::create(['track_id' => $track->id, 'station_id' => $this->station1->id, 'step_order' => 1, 'is_required' => true]);
        TrackStep::create(['track_id' => $track->id, 'station_id' => $this->station2->id, 'step_order' => 2, 'is_required' => true]);
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
            'track_id' => $track->id,
            'alias' => 'A1',
            'client_category' => 'PWD',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);
        $token->update(['current_session_id' => $this->session->id]);
    }

    public function test_expired_temp_pin_returns_401(): void
    {
        $scanToken = Str::random(64);
        TemporaryAuthorization::create([
            'user_id' => $this->supervisor->id,
            'token_hash' => Hash::make('111111'),
            'type' => 'pin',
            'expires_at' => now()->subMinutes(1),
        ]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/override", [
            'target_track_id' => $this->trackToStation2->id,
            'reason' => 'Skip',
            'auth_type' => 'temp_pin',
            'temp_code' => '111111',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Authorization expired. Request a new one.');
    }

    public function test_expired_temp_qr_returns_401(): void
    {
        $scanToken = Str::random(64);
        TemporaryAuthorization::create([
            'user_id' => $this->supervisor->id,
            'token_hash' => Hash::make($scanToken),
            'type' => 'qr',
            'expires_at' => now()->subMinutes(1),
        ]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/override", [
            'target_track_id' => $this->trackToStation2->id,
            'reason' => 'Skip',
            'auth_type' => 'temp_qr',
            'qr_scan_token' => $scanToken,
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Authorization expired. Request a new one.');
    }

    public function test_invalid_temp_code_returns_401(): void
    {
        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/override", [
            'target_track_id' => $this->trackToStation2->id,
            'reason' => 'Skip',
            'auth_type' => 'temp_pin',
            'temp_code' => '999999',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Authorization expired. Request a new one.');
    }

    public function test_wrong_preset_pin_five_times_returns_429(): void
    {
        $key = 'pin_auth_fail:'.$this->staff->id;
        RateLimiter::clear($key);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/override", [
                'target_track_id' => $this->trackToStation2->id,
                'reason' => 'Skip',
                'auth_type' => 'preset_pin',
                'supervisor_user_id' => $this->supervisor->id,
                'supervisor_pin' => '000000',
            ]);
            $response->assertStatus(401);
        }

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/override", [
            'target_track_id' => $this->trackToStation2->id,
            'reason' => 'Skip',
            'auth_type' => 'preset_pin',
            'supervisor_user_id' => $this->supervisor->id,
            'supervisor_pin' => '000000',
        ]);

        $response->assertStatus(429);
        $response->assertJsonPath('message', 'Too many attempts. Try again in 15 minutes.');
    }

    public function test_call_regular_without_auth_when_override_required_returns_401(): void
    {
        $this->station1->update(['priority_first_override' => false]);
        $regularToken = new Token;
        $regularToken->qr_code_hash = hash('sha256', Str::random(32).'B1');
        $regularToken->physical_id = 'B1';
        $regularToken->status = 'in_use';
        $regularToken->save();
        $regularSession = Session::create([
            'token_id' => $regularToken->id,
            'program_id' => $this->program->id,
            'track_id' => $this->session->track_id,
            'alias' => 'B1',
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'queued_at_station' => now()->subMinutes(5),
        ]);
        $regularToken->update(['current_session_id' => $regularSession->id]);
        $this->session->update(['status' => 'waiting', 'queued_at_station' => now()->subMinutes(2)]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$regularSession->id}/call", []);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Calling this client would skip priority clients. Supervisor authorization required.');
    }

    public function test_call_regular_with_temp_pin_when_override_required_returns_200(): void
    {
        $this->station1->update(['priority_first_override' => false]);
        $regularToken = new Token;
        $regularToken->qr_code_hash = hash('sha256', Str::random(32).'B1');
        $regularToken->physical_id = 'B1';
        $regularToken->status = 'in_use';
        $regularToken->save();
        $regularSession = Session::create([
            'token_id' => $regularToken->id,
            'program_id' => $this->program->id,
            'track_id' => $this->session->track_id,
            'alias' => 'B1',
            'client_category' => 'Regular',
            'current_station_id' => $this->station1->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'queued_at_station' => now()->subMinutes(5),
        ]);
        $regularToken->update(['current_session_id' => $regularSession->id]);
        $this->session->update(['status' => 'waiting', 'queued_at_station' => now()->subMinutes(2)]);

        $genRes = $this->actingAs($this->supervisor)->postJson('/api/auth/temporary-pin', ['expires_in_seconds' => 300]);
        $genRes->assertStatus(201);
        $code = $genRes->json('code');

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$regularSession->id}/call", [
            'auth_type' => 'temp_pin',
            'temp_code' => $code,
        ]);

        $response->assertStatus(200);
        $regularSession->refresh();
        $this->assertSame('called', $regularSession->status);
    }

    public function test_override_with_preset_qr_returns_200(): void
    {
        $qrToken = 'preset-qr-token-'.Str::random(8);
        $this->supervisor->update(['override_qr_token' => Hash::make($qrToken)]);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/override", [
            'target_track_id' => $this->trackToStation2->id,
            'reason' => 'Preset QR auth',
            'auth_type' => 'preset_qr',
            'qr_scan_token' => $qrToken,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('session.track.id', $this->trackToStation2->id);
        $this->session->refresh();
        $this->assertSame($this->trackToStation2->id, $this->session->track_id);
    }

    public function test_override_with_preset_qr_by_non_supervisor_for_program_returns_403(): void
    {
        $plainStaff = User::factory()->create(['role' => 'staff']);
        $qrToken = 'preset-qr-token-'.Str::random(8);
        $plainStaff->update(['override_qr_token' => Hash::make($qrToken)]);
        $this->program->supervisedBy()->detach($this->supervisor->id);

        $response = $this->actingAs($this->staff)->postJson("/api/sessions/{$this->session->id}/override", [
            'target_track_id' => $this->trackToStation2->id,
            'reason' => 'Preset QR',
            'auth_type' => 'preset_qr',
            'qr_scan_token' => $qrToken,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'You are not a supervisor for this program. Preset authorization cannot be used here.');
    }
}
