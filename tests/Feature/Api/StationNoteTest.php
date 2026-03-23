<?php

namespace Tests\Feature\Api;

use App\Models\Program;
use App\Models\Station;
use App\Models\StationNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationNoteTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->admin()->create();
        $program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $this->station = Station::create([
            'program_id' => $program->id,
            'name' => 'Desk 1',
            'capacity' => 1,
            'client_capacity' => 1,
            'is_active' => true,
        ]);
        $this->staff = User::factory()->create([
            'assigned_station_id' => $this->station->id,
        ]);
    }

    public function test_show_returns_null_note_when_no_note_exists(): void
    {
        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station->id}/notes");

        $response->assertStatus(200);
        $response->assertJsonPath('note', null);
    }

    public function test_show_returns_note_when_exists(): void
    {
        StationNote::create([
            'station_id' => $this->station->id,
            'message' => 'Back in 5 min',
            'updated_by' => $this->staff->id,
        ]);

        $response = $this->actingAs($this->staff)->getJson("/api/stations/{$this->station->id}/notes");

        $response->assertStatus(200);
        $response->assertJsonPath('note.message', 'Back in 5 min');
    }

    public function test_update_creates_note(): void
    {
        $response = $this->actingAs($this->staff)->putJson("/api/stations/{$this->station->id}/notes", [
            'message' => 'Need supervisor',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('note.message', 'Need supervisor');
        $this->assertDatabaseHas('station_notes', [
            'station_id' => $this->station->id,
            'message' => 'Need supervisor',
        ]);
    }

    public function test_update_validates_message_max_length(): void
    {
        $response = $this->actingAs($this->staff)->putJson("/api/stations/{$this->station->id}/notes", [
            'message' => str_repeat('x', 501),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('message');
    }

    public function test_notes_require_station_access(): void
    {
        $otherStaff = User::factory()->create(['assigned_station_id' => null]);

        $response = $this->actingAs($otherStaff)->getJson("/api/stations/{$this->station->id}/notes");

        $response->assertStatus(403);
    }

    public function test_notes_require_auth(): void
    {
        $response = $this->getJson("/api/stations/{$this->station->id}/notes");

        $response->assertStatus(401);
    }
}
