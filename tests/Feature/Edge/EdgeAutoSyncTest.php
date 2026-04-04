<?php

namespace Tests\Feature\Edge;

use App\Events\EdgeSyncableEventCreated;
use App\Models\EdgeDeviceState;
use App\Models\EdgeSyncQueueItem;
use App\Models\Program;
use App\Models\User;
use App\Models\ServiceTrack;
use App\Models\Session;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\TransactionLog;
use App\Listeners\PushEdgeEventToCentral;
use App\Models\EdgeDevice;
use App\Models\Site;
use App\Services\EdgeEventPushService;
use App\Services\EdgeModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class EdgeAutoSyncTest extends TestCase
{
    use RefreshDatabase;

    private function createMinimalSession(string $syncMode = 'auto', bool $sessionActive = true): Session
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
        $token->qr_code_hash = hash('sha256', Str::random(32).uniqid());
        $token->physical_id = 'A1-'.uniqid();
        $token->status = 'in_use';
        $token->save();

        $state = EdgeDeviceState::current();
        $state->update([
            'sync_mode' => $syncMode,
            'session_active' => $sessionActive,
        ]);

        return Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'station_queue_position' => 1,
            'status' => 'waiting',
        ]);
    }

    public function test_transaction_log_created_fires_edge_sync_event_on_edge_auto_mode(): void
    {
        Event::fake([EdgeSyncableEventCreated::class]);

        $this->app->bind(EdgeModeService::class, function () {
            $mock = \Mockery::mock(EdgeModeService::class);
            $mock->shouldReceive('isEdge')->andReturn(true);
            return $mock;
        });

        $session = $this->createMinimalSession('auto', true);

        TransactionLog::create([
            'session_id' => $session->id,
            'station_id' => null,
            'staff_user_id' => null,
            'action_type' => 'bind',
        ]);

        Event::assertDispatched(EdgeSyncableEventCreated::class, function ($event) use ($session) {
            return $event->transactionLog->action_type === 'bind'
                && $event->session->id === $session->id;
        });
    }

    public function test_transaction_log_created_does_not_fire_on_central(): void
    {
        Event::fake([EdgeSyncableEventCreated::class]);

        $this->app->bind(EdgeModeService::class, function () {
            $mock = \Mockery::mock(EdgeModeService::class);
            $mock->shouldReceive('isEdge')->andReturn(false);
            return $mock;
        });

        $session = $this->createMinimalSession('auto', true);

        TransactionLog::create([
            'session_id' => $session->id,
            'station_id' => null,
            'staff_user_id' => null,
            'action_type' => 'bind',
        ]);

        Event::assertNotDispatched(EdgeSyncableEventCreated::class);
    }

    public function test_transaction_log_created_does_not_fire_on_end_of_event_mode(): void
    {
        Event::fake([EdgeSyncableEventCreated::class]);

        $this->app->bind(EdgeModeService::class, function () {
            $mock = \Mockery::mock(EdgeModeService::class);
            $mock->shouldReceive('isEdge')->andReturn(true);
            return $mock;
        });

        $session = $this->createMinimalSession('end_of_event', true);

        TransactionLog::create([
            'session_id' => $session->id,
            'station_id' => null,
            'staff_user_id' => null,
            'action_type' => 'bind',
        ]);

        Event::assertNotDispatched(EdgeSyncableEventCreated::class);
    }

    public function test_edge_sync_queue_item_can_be_created(): void
    {
        $item = EdgeSyncQueueItem::create([
            'transaction_log_id' => 1,
            'session_id' => 100,
            'event_type' => 'transaction_log',
            'payload' => ['action_type' => 'bind', 'session_id' => 100],
            'attempts' => 0,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('edge_sync_queue', [
            'id' => $item->id,
            'status' => 'pending',
            'event_type' => 'transaction_log',
        ]);
    }

    public function test_retryable_scope_excludes_maxed_out_items(): void
    {
        EdgeSyncQueueItem::create([
            'event_type' => 'transaction_log',
            'payload' => ['action_type' => 'bind'],
            'attempts' => 5,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        EdgeSyncQueueItem::create([
            'event_type' => 'transaction_log',
            'payload' => ['action_type' => 'call'],
            'attempts' => 2,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        $retryable = EdgeSyncQueueItem::retryable()->get();
        $this->assertCount(1, $retryable);
        $this->assertEquals('call', $retryable->first()->payload['action_type']);
    }

    public function test_mark_sent_updates_status_and_synced_at(): void
    {
        $item = EdgeSyncQueueItem::create([
            'event_type' => 'transaction_log',
            'payload' => ['action_type' => 'bind'],
            'attempts' => 1,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        $item->markSent();

        $item->refresh();
        $this->assertEquals('sent', $item->status);
        $this->assertNotNull($item->synced_at);
    }

    public function test_push_service_sends_http_post_to_central(): void
    {
        Http::fake([
            '*/api/edge/event' => Http::response(['status' => 'ok'], 200),
        ]);

        $state = EdgeDeviceState::current();
        $state->update([
            'central_url' => 'https://central.test',
            'device_token' => 'test-token-abc',
            'sync_mode' => 'auto',
            'session_active' => true,
        ]);

        $service = new EdgeEventPushService();
        $result = $service->push('transaction_log', [
            'id' => 1,
            'session_id' => 100,
            'action_type' => 'bind',
            'created_at' => '2026-04-04T10:00:00Z',
        ], transactionLogId: 1, sessionId: 100);

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'https://central.test/api/edge/event')
                && $request->hasHeader('Authorization', 'Bearer test-token-abc')
                && $request['event_type'] === 'transaction_log'
                && $request['payload']['action_type'] === 'bind';
        });
    }

    public function test_push_service_queues_on_http_failure(): void
    {
        Http::fake([
            '*/api/edge/event' => Http::response('Server Error', 500),
        ]);

        $state = EdgeDeviceState::current();
        $state->update([
            'central_url' => 'https://central.test',
            'device_token' => 'test-token-abc',
            'sync_mode' => 'auto',
            'session_active' => true,
        ]);

        $service = new EdgeEventPushService();
        $result = $service->push('transaction_log', [
            'id' => 1,
            'session_id' => 100,
            'action_type' => 'call',
            'created_at' => '2026-04-04T10:00:00Z',
        ], transactionLogId: 1, sessionId: 100);

        $this->assertFalse($result);

        $this->assertDatabaseHas('edge_sync_queue', [
            'event_type' => 'transaction_log',
            'status' => 'pending',
            'transaction_log_id' => 1,
        ]);
    }

    public function test_push_service_includes_session_state_in_payload(): void
    {
        Http::fake([
            '*/api/edge/event' => Http::response(['status' => 'ok'], 200),
        ]);

        $state = EdgeDeviceState::current();
        $state->update([
            'central_url' => 'https://central.test',
            'device_token' => 'test-token-abc',
            'sync_mode' => 'auto',
            'session_active' => true,
        ]);

        $session = $this->createMinimalSession('auto', true);
        $session->update(['status' => 'called', 'current_station_id' => 1]);

        $service = new EdgeEventPushService();
        $service->pushWithSession('transaction_log', [
            'id' => 1,
            'session_id' => $session->id,
            'action_type' => 'call',
            'created_at' => now()->toIso8601String(),
        ], $session, transactionLogId: 1);

        Http::assertSent(function ($request) use ($session) {
            return isset($request['session_state'])
                && $request['session_state']['id'] === $session->id
                && $request['session_state']['status'] === 'called';
        });
    }

    public function test_listener_pushes_transaction_log_to_central(): void
    {
        Http::fake([
            '*/api/edge/event' => Http::response(['status' => 'ok'], 200),
        ]);

        $state = EdgeDeviceState::current();
        $state->update([
            'central_url' => 'https://central.test',
            'device_token' => 'test-token-abc',
            'sync_mode' => 'auto',
            'session_active' => true,
        ]);

        $session = $this->createMinimalSession('auto', true);

        $log = TransactionLog::create([
            'session_id' => $session->id,
            'station_id' => null,
            'staff_user_id' => null,
            'action_type' => 'bind',
        ]);

        $event = new \App\Events\EdgeSyncableEventCreated($log, $session);
        $listener = new PushEdgeEventToCentral(new EdgeEventPushService());
        $listener->handle($event);

        Http::assertSent(function ($request) {
            return $request['event_type'] === 'transaction_log'
                && $request['payload']['action_type'] === 'bind'
                && isset($request['session_state']);
        });
    }

    private function createCentralSessionAndDevice(string $rawToken, int $sessionId, array $sessionData = []): array
    {
        $site = Site::factory()->create();
        $program = Program::factory()->for($site)->create();

        $user = User::factory()->admin()->create();
        $program->update(['created_by' => $user->id]);

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
        $token->qr_code_hash = hash('sha256', Str::random(32).$rawToken);
        $token->physical_id = 'TOKEN-'.Str::random(4);
        $token->status = 'in_use';
        $token->save();

        $device = EdgeDevice::factory()->create([
            'site_id' => $site->id,
            'device_token_hash' => hash('sha256', $rawToken),
            'assigned_program_id' => $program->id,
            'session_active' => true,
            'id_offset' => 10_000_000,
        ]);

        $session = Session::create(array_merge([
            'id' => $sessionId,
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'status' => 'waiting',
        ], $sessionData));

        return [$session, $rawToken, $device, $program];
    }

    public function test_central_event_endpoint_accepts_valid_transaction_log(): void
    {
        [$session, $rawToken] = $this->createCentralSessionAndDevice(
            'device-token-secret-123',
            10_000_001
        );

        $response = $this->postJson('/api/edge/event', [
            'event_type' => 'transaction_log',
            'payload' => [
                'id' => 10_000_001,
                'session_id' => $session->id,
                'station_id' => null,
                'staff_user_id' => null,
                'action_type' => 'bind',
                'created_at' => '2026-04-04T10:00:00Z',
            ],
            'session_state' => [
                'id' => $session->id,
                'status' => 'waiting',
                'current_station_id' => null,
                'updated_at' => '2026-04-04T10:00:00Z',
            ],
        ], ['Authorization' => "Bearer {$rawToken}"]);

        $response->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertDatabaseHas('transaction_logs', [
            'session_id' => $session->id,
            'action_type' => 'bind',
        ]);
    }

    public function test_central_event_endpoint_rejects_without_auth(): void
    {
        $response = $this->postJson('/api/edge/event', [
            'event_type' => 'transaction_log',
            'payload' => ['action_type' => 'bind'],
        ]);

        $response->assertUnauthorized();
    }

    public function test_central_event_endpoint_rejects_invalid_event_type(): void
    {
        $rawToken = 'device-token-secret-456';
        $site = Site::factory()->create();
        EdgeDevice::factory()->create([
            'site_id' => $site->id,
            'device_token_hash' => hash('sha256', $rawToken),
            'session_active' => true,
        ]);

        $response = $this->postJson('/api/edge/event', [
            'event_type' => 'not_a_real_type',
            'payload' => ['id' => 1],
        ], ['Authorization' => "Bearer {$rawToken}"]);

        $response->assertUnprocessable();
    }

    public function test_central_event_endpoint_updates_session_state(): void
    {
        [$session, $rawToken] = $this->createCentralSessionAndDevice(
            'device-token-secret-789',
            10_000_002
        );

        $this->postJson('/api/edge/event', [
            'event_type' => 'transaction_log',
            'payload' => [
                'id' => 10_000_002,
                'session_id' => $session->id,
                'station_id' => 1,
                'staff_user_id' => 1,
                'action_type' => 'call',
                'created_at' => '2026-04-04T10:05:00Z',
            ],
            'session_state' => [
                'id' => $session->id,
                'status' => 'called',
                'current_station_id' => 1,
                'updated_at' => '2026-04-04T10:05:00Z',
            ],
        ], ['Authorization' => "Bearer {$rawToken}"]);

        $this->assertDatabaseHas('queue_sessions', [
            'id' => $session->id,
            'status' => 'called',
        ]);
    }

    public function test_sync_retry_command_pushes_pending_items(): void
    {
        config(['app.mode' => 'edge']);

        Http::fake([
            '*/api/edge/event' => Http::response(['status' => 'ok'], 200),
        ]);

        $state = EdgeDeviceState::current();
        $state->update([
            'central_url' => 'https://central.test',
            'device_token' => 'test-token-abc',
            'sync_mode' => 'auto',
            'session_active' => true,
        ]);

        EdgeSyncQueueItem::create([
            'transaction_log_id' => 1,
            'session_id' => 100,
            'event_type' => 'transaction_log',
            'payload' => ['id' => 1, 'session_id' => 100, 'action_type' => 'bind', 'created_at' => now()->toIso8601String()],
            'attempts' => 1,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        $this->artisan('edge:sync-retry')->assertSuccessful();

        $this->assertDatabaseHas('edge_sync_queue', [
            'transaction_log_id' => 1,
            'status' => 'sent',
        ]);
    }

    public function test_sync_retry_degrades_after_5_consecutive_failures(): void
    {
        config(['app.mode' => 'edge']);

        Http::fake([
            '*/api/edge/event' => Http::response('Error', 500),
        ]);

        $state = EdgeDeviceState::current();
        $state->update([
            'central_url' => 'https://central.test',
            'device_token' => 'test-token-abc',
            'sync_mode' => 'auto',
            'session_active' => true,
        ]);

        // Create 1 item with 4 prior attempts (next failure = 5th = degrade)
        EdgeSyncQueueItem::create([
            'transaction_log_id' => 1,
            'session_id' => 100,
            'event_type' => 'transaction_log',
            'payload' => ['id' => 1, 'session_id' => 100, 'action_type' => 'bind', 'created_at' => now()->toIso8601String()],
            'attempts' => 4,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        $this->artisan('edge:sync-retry')->assertSuccessful();

        $this->assertTrue(Cache::get('edge.sync_degraded', false));
    }

    public function test_sync_retry_resumes_on_successful_push(): void
    {
        config(['app.mode' => 'edge']);

        Http::fake([
            '*/api/edge/event' => Http::response(['status' => 'ok'], 200),
        ]);

        Cache::put('edge.sync_degraded', true);

        $state = EdgeDeviceState::current();
        $state->update([
            'central_url' => 'https://central.test',
            'device_token' => 'test-token-abc',
            'sync_mode' => 'auto',
            'session_active' => true,
        ]);

        EdgeSyncQueueItem::create([
            'transaction_log_id' => 2,
            'session_id' => 101,
            'event_type' => 'transaction_log',
            'payload' => ['id' => 2, 'session_id' => 101, 'action_type' => 'call', 'created_at' => now()->toIso8601String()],
            'attempts' => 2,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        $this->artisan('edge:sync-retry')->assertSuccessful();

        $this->assertFalse(Cache::get('edge.sync_degraded', false));
        $this->assertDatabaseHas('edge_sync_queue', [
            'transaction_log_id' => 2,
            'status' => 'sent',
        ]);
    }

    public function test_sync_retry_skips_when_not_edge(): void
    {
        $this->app->bind(EdgeModeService::class, function () {
            $mock = \Mockery::mock(EdgeModeService::class);
            $mock->shouldReceive('isEdge')->andReturn(false);
            return $mock;
        });

        $this->artisan('edge:sync-retry')
            ->expectsOutputToContain('Not running on edge')
            ->assertSuccessful();
    }
}
