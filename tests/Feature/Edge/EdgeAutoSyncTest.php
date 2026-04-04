<?php

namespace Tests\Feature\Edge;

use App\Models\EdgeSyncQueueItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EdgeAutoSyncTest extends TestCase
{
    use RefreshDatabase;

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
