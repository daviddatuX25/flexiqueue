<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Process;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per PROCESS-STATION-REFACTOR §9.1: Process list endpoint.
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
        $response->assertJsonStructure(['process' => ['id', 'program_id', 'name', 'description', 'created_at']]);
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
}
