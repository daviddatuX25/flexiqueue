<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Per docs/final-edge-mode-rush-plann.md [DF-21]: Edge import trigger and status API.
 */
class EdgeImportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->cleanEdgeImportArtifacts();
    }

    protected function tearDown(): void
    {
        $this->cleanEdgeImportArtifacts();
        parent::tearDown();
    }

    private function cleanEdgeImportArtifacts(): void
    {
        $lockPath = storage_path('app/edge_import_running.lock');
        if (file_exists($lockPath)) {
            @unlink($lockPath);
        }
        Storage::disk('local')->delete('edge_import_running.lock');
        Storage::disk('local')->delete('edge_package_imported.json');
    }

    public function test_post_import_returns_403_when_app_mode_is_central(): void
    {
        config(['app.mode' => 'central']);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/edge/import', [
            'program_id' => 1,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'This endpoint is only available in edge mode.');
    }

    public function test_post_import_returns_403_when_unauthenticated(): void
    {
        config(['app.mode' => 'edge']);

        $response = $this->postJson('/api/admin/edge/import', [
            'program_id' => 1,
        ]);

        $response->assertStatus(401);
    }

    public function test_get_import_status_returns_403_when_app_mode_is_central(): void
    {
        config(['app.mode' => 'central']);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/edge/import/status');

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'This endpoint is only available in edge mode.');
    }

    public function test_post_import_returns_409_when_lock_file_exists(): void
    {
        config(['app.mode' => 'edge']);
        Storage::disk('local')->put('edge_import_running.lock', '');

        $response = $this->actingAs($this->admin)->postJson('/api/admin/edge/import', [
            'program_id' => 1,
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('status', 'already_running');
    }

    public function test_post_import_returns_200_queued_when_app_mode_edge_and_valid_program_id(): void
    {
        config(['app.mode' => 'edge']);
        $this->app['env'] = 'testing';
        putenv('CENTRAL_URL=http://central.test');
        putenv('CENTRAL_API_KEY=test-key');

        $response = $this->actingAs($this->admin)->postJson('/api/admin/edge/import', [
            'program_id' => 1,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'queued');
    }

    public function test_get_import_status_returns_never_synced_when_no_status_file(): void
    {
        config(['app.mode' => 'edge']);
        $this->cleanEdgeImportArtifacts();

        $response = $this->actingAs($this->admin)->getJson('/api/admin/edge/import/status');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'never_synced');
    }

    public function test_get_import_status_returns_correct_data_when_status_file_exists(): void
    {
        config(['app.mode' => 'edge']);
        $data = [
            'status' => 'completed',
            'imported_at' => now()->toIso8601String(),
            'program_id' => 1,
        ];
        Storage::disk('local')->put('edge_package_imported.json', json_encode($data));

        $response = $this->actingAs($this->admin)->getJson('/api/admin/edge/import/status');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'completed');
        $response->assertJsonPath('program_id', 1);
    }

    public function test_get_import_status_returns_running_when_lock_file_exists(): void
    {
        config(['app.mode' => 'edge']);
        Storage::disk('local')->put('edge_import_running.lock', '');

        $response = $this->actingAs($this->admin)->getJson('/api/admin/edge/import/status');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'running');
    }
}
