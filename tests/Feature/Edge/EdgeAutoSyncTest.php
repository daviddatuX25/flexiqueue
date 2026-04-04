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
use App\Services\EdgeModeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
