<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Per 08-API-SPEC-PHASE1 §7.2: broadcast queue_length to queue.{programId} when queue changes.
 * Per central-edge-v2-final Phase A: global.queue → queue.{programId}; display.station.{id} unchanged.
 */
class QueueLengthUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $programId,
        public int $stationId
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('queue.'.$this->programId),
            new Channel('display.station.'.$this->stationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'queue_length';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'station_id' => $this->stationId,
        ];
    }
}
