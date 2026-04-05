<?php

namespace Tests\Feature\Edge;

use App\Models\EdgeDeviceState;
use App\Models\EdgeSyncReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EdgeSyncPageTest extends TestCase
{
    use RefreshDatabase;

    private function actAsEdge(): void
    {
        config(['app.mode' => 'edge']);
    }

    private function seedPairedState(array $overrides = []): void
    {
        EdgeDeviceState::where('id', 1)->updateOrInsert(['id' => 1], array_merge([
            'paired_at'           => now(),
            'device_token'        => Crypt::encrypt('test-token'),
            'central_url'         => 'http://central.test',
            'site_name'           => 'Test Site',
            'active_program_id'   => 1,
            'active_program_name' => 'Test Program',
            'sync_mode'           => 'auto',
            'session_active'      => false,
            'is_revoked'          => false,
            'package_version'     => 'abc123',
            'package_stale'       => false,
            'update_available'    => false,
        ], $overrides));
    }

    public function test_sync_page_requires_authentication(): void
    {
        $this->actAsEdge();
        $this->seedPairedState();

        $this->get('/edge/sync')
            ->assertRedirect('/login');
    }

    public function test_sync_page_returns_200_for_authenticated_admin(): void
    {
        $this->actAsEdge();
        $this->seedPairedState();

        $user = User::factory()->admin()->create();
        $this->actingAs($user)
            ->get('/edge/sync')
            ->assertStatus(200);
    }

    public function test_sync_page_includes_device_state_in_inertia_props(): void
    {
        $this->actAsEdge();
        $this->seedPairedState();

        $user = User::factory()->admin()->create();
        $this->actingAs($user)
            ->get('/edge/sync')
            ->assertInertia(fn ($page) => $page
                ->component('Edge/Sync')
                ->has('device')
                ->where('device.site_name', 'Test Site')
                ->where('device.active_program_name', 'Test Program')
                ->where('device.package_version', 'abc123')
            );
    }

    public function test_sync_page_returns_never_synced_when_no_import_file(): void
    {
        $this->actAsEdge();
        $this->seedPairedState();
        Storage::fake('local');

        $user = User::factory()->admin()->create();
        $this->actingAs($user)
            ->get('/edge/sync')
            ->assertInertia(fn ($page) => $page
                ->where('import.status', 'never_synced')
            );
    }

    public function test_sync_page_returns_complete_when_import_file_exists(): void
    {
        $this->actAsEdge();
        $this->seedPairedState();
        Storage::fake('local');
        Storage::disk('local')->put('edge_package_imported.json', json_encode([
            'status'      => 'complete',
            'imported_at' => '2026-04-05T10:00:00+00:00',
            'program_id'  => 1,
            'sync_tokens' => true,
            'sync_clients'=> false,
        ]));

        $user = User::factory()->admin()->create();
        $this->actingAs($user)
            ->get('/edge/sync')
            ->assertInertia(fn ($page) => $page
                ->where('import.status', 'complete')
                ->where('import.imported_at', '2026-04-05T10:00:00+00:00')
                ->where('import.sync_tokens', true)
            );
    }

    public function test_sync_page_returns_running_when_lock_file_exists(): void
    {
        $this->actAsEdge();
        $this->seedPairedState();
        Storage::fake('local');
        Storage::disk('local')->put('edge_import_running.lock', now()->toIso8601String());

        $user = User::factory()->admin()->create();
        $this->actingAs($user)
            ->get('/edge/sync')
            ->assertInertia(fn ($page) => $page
                ->where('import.status', 'running')
            );
    }

    public function test_sync_page_includes_recent_sync_receipts(): void
    {
        $this->actAsEdge();
        $this->seedPairedState();

        EdgeSyncReceipt::create([
            'batch_id'        => 'batch-001',
            'status'          => 'complete',
            'payload_summary' => ['sessions' => 5, 'logs' => 12],
            'receipt_data'    => ['accepted' => 5],
            'started_at'      => now()->subMinutes(5),
            'completed_at'    => now()->subMinutes(4),
            'created_at'      => now()->subMinutes(5),
        ]);

        $user = User::factory()->admin()->create();
        $this->actingAs($user)
            ->get('/edge/sync')
            ->assertInertia(fn ($page) => $page
                ->has('receipts', 1)
                ->where('receipts.0.batch_id', 'batch-001')
                ->where('receipts.0.status', 'complete')
            );
    }

    public function test_sync_page_limits_receipts_to_ten(): void
    {
        $this->actAsEdge();
        $this->seedPairedState();

        for ($i = 1; $i <= 12; $i++) {
            EdgeSyncReceipt::create([
                'batch_id'   => "batch-{$i}",
                'status'     => 'complete',
                'started_at' => now()->subMinutes($i),
                'created_at' => now()->subMinutes($i),
            ]);
        }

        $user = User::factory()->admin()->create();
        $this->actingAs($user)
            ->get('/edge/sync')
            ->assertInertia(fn ($page) => $page
                ->has('receipts', 10)
            );
    }
}
