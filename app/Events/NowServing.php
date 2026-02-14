<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Per 08-API-SPEC-PHASE1 §3.2: broadcast now_serving to global.queue when serving state changes.
 */
class NowServing implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $stationId,
        public array $payload
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('global.queue'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'now_serving';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return array_merge(['station_id' => $this->stationId], $this->payload);
    }
}
