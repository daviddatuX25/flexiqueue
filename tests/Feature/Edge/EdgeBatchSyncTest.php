<?php

namespace Tests\Feature\Edge;

use App\Models\EdgeDeviceState;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\User;
use App\Services\EdgeBatchSyncService;
use App\Services\EdgeModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class EdgeBatchSyncTest extends TestCase
{
    use RefreshDatabase;

    private function setupEdgeState(string $syncMode = 'end_of_event', bool $sessionActive = false): EdgeDeviceState
    {
        $state = EdgeDeviceState::current();
        $state->update([
            'central_url' => 'https://central.test',
            'device_token' => 'test-token-abc',
            'sync_mode' => $syncMode,
            'session_active' => $sessionActive,
            'active_program_id' => 1,
            'site_id' => 1,
        ]);
        return $state;
    }

    private function createEdgeSessionData(): array
    {
        $user = User::factory()->admin()->create();
        $program = Program::create([
            'name' => 'Test Program',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'S1',
            'capacity' => 1,
            'is_active' => true,
        ]);

        $track = ServiceTrack::create([
            'program_id' => $program->id,
            'name' => 'Default',
            'is_default' => true,
            'color_code' => '#333',
        ]);

        TrackStep::create([
            'track_id' => $track->id,
            'station_id' => $station->id,
            'step_order' => 1,
            'is_required' => true,
        ]);

        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32));
        $token->physical_id = 'A1-' . uniqid();
        $token->status = 'in_use';
        $token->save();

        $session = Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'station_queue_position' => 1,
            'status' => 'completed',
        ]);

        // Bypass TransactionLog::create to avoid model hooks
        DB::table('transaction_logs')->insert([
            'session_id' => $session->id,
            'station_id' => $station->id,
            'staff_user_id' => $user->id,
            'action_type' => 'bind',
            'created_at' => now(),
        ]);

        DB::table('transaction_logs')->insert([
            'session_id' => $session->id,
            'station_id' => $station->id,
            'staff_user_id' => $user->id,
            'action_type' => 'complete',
            'created_at' => now(),
        ]);

        return [$session, $program, $token];
    }

    public function test_batch_sync_service_collects_unsynced_data(): void
    {
        $this->setupEdgeState('end_of_event', false);
        [$session, $program, $token] = $this->createEdgeSessionData();

        $service = new EdgeBatchSyncService();
        $payload = $service->collectUnsyncedData();

        $this->assertArrayHasKey('session_summary', $payload);
        $this->assertArrayHasKey('queue_sessions', $payload);
        $this->assertArrayHasKey('transaction_logs', $payload);
        $this->assertArrayHasKey('token_updates', $payload);

        $this->assertCount(1, $payload['queue_sessions']);
        $this->assertCount(2, $payload['transaction_logs']);
        $this->assertEquals($session->id, $payload['queue_sessions'][0]['id']);
    }

    public function test_batch_sync_service_returns_empty_when_nothing_to_sync(): void
    {
        $state = $this->setupEdgeState('end_of_event', false);
        $state->update(['last_synced_at' => now()]);

        $service = new EdgeBatchSyncService();
        $payload = $service->collectUnsyncedData();

        $this->assertEmpty($payload['queue_sessions']);
        $this->assertEmpty($payload['transaction_logs']);
    }

    public function test_batch_sync_service_pushes_to_central_and_stores_receipt(): void
    {
        Http::fake([
            '*/api/edge/sync' => Http::response([
                'status' => 'complete',
                'synced_at' => '2026-04-04T17:05:00Z',
                'records_received' => [
                    'queue_sessions' => 1,
                    'transaction_logs' => 2,
                    'clients' => 0,
                    'identity_registrations' => 0,
                ],
                'conflicts' => [],
            ], 200),
        ]);

        $this->setupEdgeState('end_of_event', false);
        [$session, $program, $token] = $this->createEdgeSessionData();

        $service = new EdgeBatchSyncService();
        $result = $service->pushToCentral();

        $this->assertTrue($result);

        // Receipt was stored
        $this->assertDatabaseHas('edge_sync_receipts', [
            'status' => 'complete',
        ]);

        // last_synced_at was updated
        $state = EdgeDeviceState::current();
        $this->assertNotNull($state->last_synced_at);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/edge/sync')
                && isset($request['session_summary'])
                && isset($request['queue_sessions'])
                && isset($request['transaction_logs']);
        });
    }

    public function test_batch_sync_service_returns_false_on_http_failure(): void
    {
        Http::fake([
            '*/api/edge/sync' => Http::response('Server Error', 500),
        ]);

        $this->setupEdgeState('end_of_event', false);
        $this->createEdgeSessionData();

        $service = new EdgeBatchSyncService();
        $result = $service->pushToCentral();

        $this->assertFalse($result);

        // Receipt marked as failed
        $this->assertDatabaseHas('edge_sync_receipts', [
            'status' => 'failed',
        ]);
    }

    public function test_batch_sync_service_skips_when_nothing_to_sync(): void
    {
        Http::fake();

        $state = $this->setupEdgeState('end_of_event', false);
        $state->update(['last_synced_at' => now()]);

        $service = new EdgeBatchSyncService();
        $result = $service->pushToCentral();

        $this->assertTrue($result); // nothing to sync = success (no-op)
        Http::assertNothingSent();
    }

    public function test_auto_sync_command_triggers_at_scheduled_time(): void
    {
        config(['app.mode' => 'edge']);

        Http::fake([
            '*/api/edge/sync' => Http::response([
                'status' => 'complete',
                'synced_at' => now()->toIso8601String(),
                'records_received' => ['queue_sessions' => 1, 'transaction_logs' => 2, 'clients' => 0, 'identity_registrations' => 0],
                'conflicts' => [],
            ], 200),
        ]);

        $state = $this->setupEdgeState('end_of_event', false);
        $currentTime = now()->format('H:i');
        $state->update(['scheduled_sync_time' => $currentTime]);

        $this->createEdgeSessionData();

        $this->artisan('edge:auto-sync')->assertSuccessful();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/edge/sync');
        });
    }

    public function test_auto_sync_command_skips_when_not_sync_time(): void
    {
        config(['app.mode' => 'edge']);
        Http::fake();

        $state = $this->setupEdgeState('end_of_event', false);
        // Set scheduled time to 2 hours from now (never matches)
        $state->update(['scheduled_sync_time' => now()->addHours(2)->format('H:i')]);

        $this->artisan('edge:auto-sync')
            ->expectsOutputToContain('Not scheduled sync time')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_auto_sync_command_skips_on_auto_mode(): void
    {
        config(['app.mode' => 'edge']);

        $this->setupEdgeState('auto', false);

        $this->artisan('edge:auto-sync')
            ->expectsOutputToContain('not end_of_event')
            ->assertSuccessful();
    }

    public function test_auto_sync_command_retries_on_failure(): void
    {
        config(['app.mode' => 'edge']);

        Http::fake([
            '*/api/edge/sync' => Http::response('Error', 500),
        ]);

        $state = $this->setupEdgeState('end_of_event', false);
        $state->update(['scheduled_sync_time' => now()->format('H:i')]);
        $this->createEdgeSessionData();

        $this->artisan('edge:auto-sync')->assertSuccessful();

        // Should have set a retry cache key
        $this->assertTrue(Cache::has('edge.auto_sync_retry_until'));
    }

    public function test_auto_sync_command_runs_during_retry_window(): void
    {
        config(['app.mode' => 'edge']);

        Http::fake([
            '*/api/edge/sync' => Http::response([
                'status' => 'complete',
                'synced_at' => now()->toIso8601String(),
                'records_received' => ['queue_sessions' => 0, 'transaction_logs' => 1, 'clients' => 0, 'identity_registrations' => 0],
                'conflicts' => [],
            ], 200),
        ]);

        $state = $this->setupEdgeState('end_of_event', false);
        // Not the scheduled time, but retry window is active
        $state->update(['scheduled_sync_time' => now()->subHour()->format('H:i')]);

        Cache::put('edge.auto_sync_retry_until', now()->addHour()->toIso8601String(), 7200);
        Cache::put('edge.auto_sync_next_retry', now()->subMinute()->toIso8601String(), 7200);

        $this->createEdgeSessionData();

        $this->artisan('edge:auto-sync')->assertSuccessful();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/edge/sync');
        });

        // Retry keys cleared on success
        $this->assertFalse(Cache::has('edge.auto_sync_retry_until'));
    }

    public function test_sync_trigger_endpoint_starts_batch_sync(): void
    {
        config(['app.mode' => 'edge']);

        Http::fake([
            '*/api/edge/sync' => Http::response([
                'status' => 'complete',
                'synced_at' => now()->toIso8601String(),
                'records_received' => ['queue_sessions' => 1, 'transaction_logs' => 2, 'clients' => 0, 'identity_registrations' => 0],
                'conflicts' => [],
            ], 200),
        ]);

        $state = $this->setupEdgeState('end_of_event', false);
        $this->createEdgeSessionData();

        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/edge/sync-trigger');

        $response->assertOk()->assertJsonStructure([
            'status',
            'message',
        ]);

        $this->assertEquals('complete', $response->json('status'));
    }

    public function test_sync_trigger_returns_no_data_when_nothing_to_sync(): void
    {
        config(['app.mode' => 'edge']);

        // Fake both the sync endpoint AND the /api/ping health check that
        // HandleInertiaRequests middleware calls via EdgeModeService::isOnline().
        Http::fake([
            '*/api/edge/sync' => Http::response(['status' => 'complete'], 200),
            '*/api/ping' => Http::response(['ok' => true], 200),
        ]);

        $state = $this->setupEdgeState('end_of_event', false);
        $state->update(['last_synced_at' => now()]);

        // hasUnsyncedData checks: queue_sessions, transaction_logs, clients,
        // identity_registrations, program_audit_log.
        // Use DELETE (transactional) instead of TRUNCATE to stay within RefreshDatabase rollback.
        foreach (['queue_sessions', 'transaction_logs', 'clients', 'identity_registrations', 'program_audit_log'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        if (DB::getSchemaBuilder()->hasTable('staff_activity_log')) {
            DB::table('staff_activity_log')->delete();
        }

        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/edge/sync-trigger');

        $response->assertOk();
        $this->assertEquals('no_data', $response->json('status'));

        // Only the /api/ping health check should be made (from HandleInertiaRequests
        // middleware); no /api/edge/sync push should occur.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/ping')
                && ! str_contains($request->url(), '/api/edge/sync');
        });
    }

    public function test_sync_status_endpoint_returns_current_state(): void
    {
        config(['app.mode' => 'edge']);

        $state = $this->setupEdgeState('end_of_event', false);
        $state->update(['last_synced_at' => now()->subHour()]);

        \App\Models\EdgeSyncReceipt::create([
            'batch_id' => (string) Str::uuid(),
            'status' => 'complete',
            'payload_summary' => ['queue_sessions' => 5, 'transaction_logs' => 20],
            'receipt_data' => ['status' => 'complete', 'records_received' => ['queue_sessions' => 5]],
            'started_at' => now()->subHour(),
            'completed_at' => now()->subHour(),
            'created_at' => now()->subHour(),
        ]);

        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/edge/sync-status');

        $response->assertOk()->assertJsonStructure([
            'sync_mode',
            'last_synced_at',
            'has_unsynced_data',
            'last_receipt',
        ]);
    }

    public function test_sync_receipts_endpoint_returns_recent_receipts(): void
    {
        config(['app.mode' => 'edge']);

        // Create 3 receipts
        for ($i = 1; $i <= 3; $i++) {
            \App\Models\EdgeSyncReceipt::create([
                'batch_id' => (string) Str::uuid(),
                'status' => $i === 2 ? 'failed' : 'complete',
                'payload_summary' => ['queue_sessions' => $i * 5],
                'receipt_data' => $i !== 2 ? ['status' => 'complete'] : null,
                'started_at' => now()->subHours(4 - $i),
                'completed_at' => now()->subHours(4 - $i),
                'created_at' => now()->subHours(4 - $i),
            ]);
        }

        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/edge/sync-receipts');

        $response->assertOk();
        $receipts = $response->json('receipts');
        $this->assertCount(3, $receipts);
        // Most recent first
        $this->assertEquals('complete', $receipts[0]['status']);
    }
}
