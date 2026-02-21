<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Station;
use App\Models\TrackStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per 08-API-SPEC-PHASE1 §5.3: Station CRUD. Per PROCESS-STATION-REFACTOR §9.2: process_ids required.
 */
class StationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Program $program;

    private Process $process;

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
        $this->process = Process::create([
            'program_id' => $this->program->id,
            'name' => 'Verification',
            'description' => null,
        ]);
    }

    public function test_index_returns_stations_for_program(): void
    {
        Station::create([
            'program_id' => $this->program->id,
            'name' => 'Verification Desk',
            'capacity' => 1,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/programs/{$this->program->id}/stations");

        $response->assertStatus(200);
        $response->assertJsonPath('stations.0.name', 'Verification Desk');
        $response->assertJsonPath('stations.0.is_active', true);
    }

    public function test_store_creates_station_returns_201(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/stations", [
            'name' => 'Cashier',
            'capacity' => 2,
            'process_ids' => [$this->process->id],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('station.name', 'Cashier');
        $response->assertJsonPath('station.capacity', 2);
        $response->assertJsonPath('station.process_ids', [$this->process->id]);
        $this->assertDatabaseHas('stations', [
            'program_id' => $this->program->id,
            'name' => 'Cashier',
        ]);
        $this->assertDatabaseHas('station_process', [
            'station_id' => $response->json('station.id'),
            'process_id' => $this->process->id,
        ]);
    }

    public function test_store_duplicate_name_in_program_returns_422(): void
    {
        Station::create([
            'program_id' => $this->program->id,
            'name' => 'Desk One',
            'capacity' => 1,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/stations", [
            'name' => 'Desk One',
            'capacity' => 1,
            'process_ids' => [$this->process->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_update_modifies_station(): void
    {
        $station = Station::create([
            'program_id' => $this->program->id,
            'name' => 'Original',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $station->processes()->attach($this->process->id);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/stations/{$station->id}", [
            'name' => 'Updated Name',
            'capacity' => 2,
            'is_active' => false,
            'process_ids' => [$this->process->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('station.name', 'Updated Name');
        $response->assertJsonPath('station.is_active', false);
        $station->refresh();
        $this->assertSame('Updated Name', $station->name);
    }

    public function test_destroy_blocked_when_referenced_by_track_steps_returns_400(): void
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
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station->id,
            'step_order' => 1,
            'is_required' => true,
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/stations/{$station->id}");

        $response->assertStatus(400);
        $response->assertJsonFragment(['message' => 'Cannot delete station: it is used in track steps.']);
        $this->assertDatabaseHas('stations', ['id' => $station->id]);
    }

    public function test_destroy_succeeds_when_not_used_in_track_steps_returns_204(): void
    {
        $station = Station::create([
            'program_id' => $this->program->id,
            'name' => 'Orphan Station',
            'capacity' => 1,
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/stations/{$station->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('stations', ['id' => $station->id]);
    }

    public function test_staff_cannot_access_stations_api_returns_403(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($staff)->getJson("/api/admin/programs/{$this->program->id}/stations");

        $response->assertStatus(403);
    }

    public function test_store_requires_process_ids_returns_422(): void
    {
        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$this->program->id}/stations", [
            'name' => 'No Processes',
            'capacity' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('process_ids');
    }

    public function test_list_processes_returns_assigned_process_ids(): void
    {
        $station = Station::create([
            'program_id' => $this->program->id,
            'name' => 'Desk',
            'capacity' => 1,
        ]);
        $station->processes()->attach($this->process->id);

        $response = $this->actingAs($this->admin)->getJson(
            "/api/admin/programs/{$this->program->id}/stations/{$station->id}/processes"
        );

        $response->assertStatus(200);
        $response->assertJsonPath('process_ids', [$this->process->id]);
    }

    public function test_set_processes_updates_assignment(): void
    {
        $station = Station::create([
            'program_id' => $this->program->id,
            'name' => 'Desk',
            'capacity' => 1,
        ]);
        $station->processes()->attach($this->process->id);

        $process2 = Process::create([
            'program_id' => $this->program->id,
            'name' => 'Cash Release',
            'description' => null,
        ]);

        $response = $this->actingAs($this->admin)->putJson(
            "/api/admin/programs/{$this->program->id}/stations/{$station->id}/processes",
            ['process_ids' => [$this->process->id, $process2->id]]
        );

        $response->assertStatus(200);
        $ids = $response->json('station.process_ids');
        $this->assertCount(2, $ids);
        $this->assertContains($this->process->id, $ids);
        $this->assertContains($process2->id, $ids);
        $this->assertCount(2, $station->fresh()->processes);
    }
}
