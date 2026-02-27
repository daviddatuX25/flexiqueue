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
 * Per PROCESS-STATION-REFACTOR §9.1: Process list endpoint.
 * Per ISSUES-ELABORATION §19: update and delete (block if in use).
 */
class ProcessControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_index_returns_processes_for_program(): void
    {
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        Process::create(['program_id' => $program->id, 'name' => 'Verification', 'description' => null]);
        Process::create(['program_id' => $program->id, 'name' => 'Cash Release', 'description' => null]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/programs/{$program->id}/processes");

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'processes');
        $response->assertJsonPath('processes.0.name', 'Cash Release');
        $response->assertJsonPath('processes.1.name', 'Verification');
    }

    public function test_store_creates_process(): void
    {
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$program->id}/processes", [
            'name' => 'Verification',
            'description' => 'ID check step',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('process.name', 'Verification');
        $response->assertJsonPath('process.description', 'ID check step');
        $response->assertJsonStructure(['process' => ['id', 'program_id', 'name', 'description', 'expected_time_seconds', 'created_at']]);
        $this->assertDatabaseHas('processes', [
            'program_id' => $program->id,
            'name' => 'Verification',
            'description' => 'ID check step',
        ]);
    }

    public function test_store_rejects_duplicate_name_per_program(): void
    {
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        Process::create(['program_id' => $program->id, 'name' => 'Verification', 'description' => null]);

        $response = $this->actingAs($this->admin)->postJson("/api/admin/programs/{$program->id}/processes", [
            'name' => 'Verification',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_update_process_returns_200_and_changes_name(): void
    {
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'Verification',
            'description' => 'Old desc',
        ]);

        $response = $this->actingAs($this->admin)->putJson(
            "/api/admin/programs/{$program->id}/processes/{$process->id}",
            [
                'name' => 'ID Check',
                'description' => 'New description',
            ]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('process.name', 'ID Check');
        $response->assertJsonPath('process.description', 'New description');
        $this->assertDatabaseHas('processes', [
            'id' => $process->id,
            'name' => 'ID Check',
            'description' => 'New description',
        ]);
    }

    /** Per flexiqueue-5l7: process expected time in seconds; API accepts and returns expected_time_seconds. */
    public function test_update_process_with_expected_time_seconds(): void
    {
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'Verification',
            'description' => null,
            'expected_time_seconds' => null,
        ]);

        $response = $this->actingAs($this->admin)->putJson(
            "/api/admin/programs/{$program->id}/processes/{$process->id}",
            [
                'name' => 'Verification',
                'description' => null,
                'expected_time_seconds' => 330,
            ]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('process.expected_time_seconds', 330);
        $this->assertDatabaseHas('processes', [
            'id' => $process->id,
            'expected_time_seconds' => 330,
        ]);
    }

    public function test_index_includes_expected_time_seconds(): void
    {
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        Process::create([
            'program_id' => $program->id,
            'name' => 'Verification',
            'description' => null,
            'expected_time_seconds' => 300,
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/programs/{$program->id}/processes");

        $response->assertStatus(200);
        $response->assertJsonPath('processes.0.expected_time_seconds', 300);
    }

    public function test_delete_process_not_in_use_returns_204(): void
    {
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'Orphan',
            'description' => null,
        ]);

        $response = $this->actingAs($this->admin)->deleteJson(
            "/api/admin/programs/{$program->id}/processes/{$process->id}"
        );

        $response->assertStatus(204);
        $this->assertDatabaseMissing('processes', ['id' => $process->id]);
    }

    public function test_delete_process_in_track_step_returns_422_and_process_still_exists(): void
    {
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'In Step',
            'description' => null,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Desk',
            'capacity' => 1,
            'is_active' => true,
        ]);
        // Do not attach process to station so controller hits trackSteps() message
        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
        ]);
        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station->id,
            'process_id' => $process->id,
            'step_order' => 1,
            'is_required' => true,
        ]);

        $response = $this->actingAs($this->admin)->deleteJson(
            "/api/admin/programs/{$program->id}/processes/{$process->id}"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Process is in use by one or more track steps. Remove it from track steps first.');
        $this->assertDatabaseHas('processes', ['id' => $process->id]);
    }

    public function test_delete_process_assigned_to_station_returns_422(): void
    {
        $program = Program::create([
            'name' => 'Test',
            'description' => null,
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'Assigned',
            'description' => null,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Desk',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $station->processes()->attach($process->id);

        $response = $this->actingAs($this->admin)->deleteJson(
            "/api/admin/programs/{$program->id}/processes/{$process->id}"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Process is in use by one or more stations. Remove it from station assignments first.');
        $this->assertDatabaseHas('processes', ['id' => $process->id]);
    }
}
