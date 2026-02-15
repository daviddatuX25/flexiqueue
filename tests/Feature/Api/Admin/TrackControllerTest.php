<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per 08-API-SPEC-PHASE1 §5.2: ServiceTrack CRUD. List/create under program; update/delete by track id.
 */
class TrackControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_index_returns_tracks_for_program(): void
    {
        ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Regular',
            'description' => 'Regular lane',
            'is_default' => true,
            'color_code' => '#22c55e',
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/programs/{$this->program->id}/tracks");

        $response->assertStatus(200);
        $response->assertJsonPath('tracks.0.name', 'Regular');
        $response->assertJsonPath('tracks.0.is_default', true);
        $response->assertJsonPath('tracks.0.color_code', '#22c55e');
    }

    public function test_store_creates_track_returns_201(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/tracks", [
            'name' => 'Priority Lane',
            'description' => 'PWD/Senior',
            'is_default' => true,
            'color_code' => '#F59E0B',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('track.name', 'Priority Lane');
        $response->assertJsonPath('track.is_default', true);
        $this->assertDatabaseHas('service_tracks', [
            'program_id' => $this->program->id,
            'name' => 'Priority Lane',
        ]);
    }

    public function test_store_with_is_default_unsets_other_default(): void
    {
        $existing = ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Old Default',
            'is_default' => true,
        ]);

        $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/tracks", [
            'name' => 'New Default',
            'is_default' => true,
        ]);

        $this->assertFalse($existing->fresh()->is_default);
        $this->assertTrue(ServiceTrack::where('program_id', $this->program->id)->where('name', 'New Default')->first()->is_default);
    }

    public function test_store_duplicate_name_in_program_returns_422(): void
    {
        ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Regular',
            'is_default' => true,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/tracks", [
            'name' => 'Regular',
            'is_default' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_store_validates_name_required(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/tracks", [
            'name' => '',
            'is_default' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_update_modifies_track(): void
    {
        $track = ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Original',
            'description' => null,
            'is_default' => false,
            'color_code' => null,
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/tracks/{$track->id}", [
            'name' => 'Updated Name',
            'description' => 'Updated desc',
            'is_default' => false,
            'color_code' => '#ef4444',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('track.name', 'Updated Name');
        $response->assertJsonPath('track.color_code', '#ef4444');
        $track->refresh();
        $this->assertSame('Updated Name', $track->name);
    }

    public function test_destroy_blocked_when_active_sessions_use_track_returns_400(): void
    {
        $track = ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Track',
            'is_default' => true,
        ]);
        $station = Station::create([
            'program_id' => $this->program->id,
            'name' => 'S1',
            'capacity' => 1,
        ]);
        $token = new Token;
        $token->qr_code_hash = str_repeat('a', 64);
        $token->physical_id = 'A1';
        $token->status = 'in_use';
        $token->save();
        Session::create([
            'token_id' => $token->id,
            'program_id' => $this->program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'waiting',
            'no_show_attempts' => 0,
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/tracks/{$track->id}");

        $response->assertStatus(400);
        $response->assertJsonFragment(['message' => 'Cannot delete track: active sessions use this track.']);
        $this->assertDatabaseHas('service_tracks', ['id' => $track->id]);
    }

    public function test_destroy_succeeds_when_no_active_sessions_returns_204(): void
    {
        $track = ServiceTrack::create([
            'program_id' => $this->program->id,
            'name' => 'Orphan Track',
            'is_default' => true,
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/tracks/{$track->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('service_tracks', ['id' => $track->id]);
    }

    public function test_staff_cannot_access_tracks_api_returns_403(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($staff)->getJson("/api/admin/programs/{$this->program->id}/tracks");

        $response->assertStatus(403);
    }
}
