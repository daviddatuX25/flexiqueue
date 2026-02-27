<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Process;
use App\Models\Program;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per plan Tier 3.7: Program diagram API — GET/PUT layout, GET 404 when program missing,
 * GET null when no diagram, PUT 422 when node has invalid entityId.
 */
class ProgramDiagramControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_show_returns_404_when_program_missing(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/programs/99999/diagram');

        $response->assertStatus(404);
    }

    public function test_show_returns_null_layout_when_no_diagram_saved(): void
    {
        $program = Program::create([
            'name' => 'Test Program',
            'description' => 'Desc',
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/programs/{$program->id}/diagram");

        $response->assertStatus(200);
        $response->assertJsonPath('layout', null);
    }

    public function test_update_creates_diagram_and_returns_layout(): void
    {
        $program = Program::create([
            'name' => 'Test Program',
            'description' => 'Desc',
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Counter A',
            'capacity' => 1,
        ]);
        $layout = [
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
            'nodes' => [
                [
                    'id' => 'node-1',
                    'type' => 'station',
                    'position' => ['x' => 10, 'y' => 20],
                    'data' => ['label' => 'Counter A'],
                    'entityId' => $station->id,
                ],
            ],
            'edges' => [],
        ];

        $response = $this->actingAs($this->admin)->putJson("/api/admin/programs/{$program->id}/diagram", [
            'layout' => $layout,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('layout.nodes.0.id', 'node-1');
        $response->assertJsonPath('layout.nodes.0.type', 'station');
        $response->assertJsonPath('layout.viewport.zoom', 1);
        $this->assertDatabaseHas('program_diagrams', ['program_id' => $program->id]);
    }

    public function test_update_returns_422_when_node_has_invalid_entity_id(): void
    {
        $program = Program::create([
            'name' => 'Test Program',
            'description' => 'Desc',
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $layout = [
            'nodes' => [
                [
                    'id' => 'node-1',
                    'type' => 'station',
                    'position' => ['x' => 10, 'y' => 20],
                    'data' => ['label' => 'Fake'],
                    'entityId' => 99999,
                ],
            ],
            'edges' => [],
        ];

        $response = $this->actingAs($this->admin)->putJson("/api/admin/programs/{$program->id}/diagram", [
            'layout' => $layout,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['layout.nodes.0.entityId']);
    }

    public function test_update_accepts_decoration_nodes_without_entity_id(): void
    {
        $program = Program::create([
            'name' => 'Test Program',
            'description' => 'Desc',
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $layout = [
            'nodes' => [
                [
                    'id' => 'shape-1',
                    'type' => 'shape',
                    'position' => ['x' => 0, 'y' => 0],
                    'data' => ['label' => 'Room', 'shape' => 'rectangle'],
                ],
                [
                    'id' => 'text-1',
                    'type' => 'text',
                    'position' => ['x' => 50, 'y' => 50],
                    'data' => ['text' => 'Label'],
                ],
            ],
            'edges' => [],
        ];

        $response = $this->actingAs($this->admin)->putJson("/api/admin/programs/{$program->id}/diagram", [
            'layout' => $layout,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('layout.nodes.0.type', 'shape');
        $response->assertJsonPath('layout.nodes.1.type', 'text');
    }

    /** Frontend sends entityId in node.data; backend must accept it (per UpdateProgramDiagramRequest). */
    public function test_update_accepts_entity_id_in_node_data(): void
    {
        $program = Program::create([
            'name' => 'Test Program',
            'description' => 'Desc',
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Counter A',
            'capacity' => 1,
        ]);
        $layout = [
            'nodes' => [
                [
                    'id' => 'node-1',
                    'type' => 'station',
                    'position' => ['x' => 10, 'y' => 20],
                    'data' => ['label' => 'Counter A', 'entityId' => $station->id],
                ],
            ],
            'edges' => [],
        ];

        $response = $this->actingAs($this->admin)->putJson("/api/admin/programs/{$program->id}/diagram", [
            'layout' => $layout,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('layout.nodes.0.type', 'station');
    }

    /** Diagram v2: station_group with process_handle children. */
    public function test_update_accepts_station_group_and_process_handle_nodes(): void
    {
        $program = Program::create([
            'name' => 'Test Program',
            'description' => 'Desc',
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Counter A',
            'capacity' => 2,
        ]);
        $process = Process::create([
            'program_id' => $program->id,
            'name' => 'Verification',
            'description' => null,
        ]);
        $station->processes()->attach($process->id);

        $parentId = 'sg-1';
        $handleId = 'ph-1';
        $layout = [
            'nodes' => [
                [
                    'id' => $parentId,
                    'type' => 'station_group',
                    'position' => ['x' => 0, 'y' => 0],
                    'width' => 280,
                    'height' => 200,
                    'data' => ['label' => 'Counter A', 'stationId' => $station->id],
                    'entityId' => $station->id,
                ],
                [
                    'id' => $handleId,
                    'type' => 'process_handle',
                    'position' => ['x' => 20, 'y' => 0],
                    'parentId' => $parentId,
                    'data' => ['stationId' => $station->id, 'processId' => $process->id, 'label' => 'Verification'],
                ],
            ],
            'edges' => [],
        ];

        $response = $this->actingAs($this->admin)->putJson("/api/admin/programs/{$program->id}/diagram", [
            'layout' => $layout,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('layout.nodes.0.type', 'station_group');
        $response->assertJsonPath('layout.nodes.1.type', 'process_handle');
    }
}
