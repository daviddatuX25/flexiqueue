<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Per plan: broadcast station activity to display.board for real-time activity feed.
 * Public channel for informant display.
 */
class StationActivity implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $stationId,
        public string $stationName,
        public string $message,
        public string $alias,
        public string $actionType,
        public string $createdAt
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('display.activity'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'station_activity';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'station_id' => $this->stationId,
            'station_name' => $this->stationName,
            'message' => $this->message,
            'alias' => $this->alias,
            'action_type' => $this->actionType,
            'created_at' => $this->createdAt,
        ];
    }
}
