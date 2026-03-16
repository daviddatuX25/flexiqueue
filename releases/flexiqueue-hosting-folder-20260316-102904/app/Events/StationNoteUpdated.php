<?php

namespace App\Events;

use App\Models\Station;
use Carbon\Carbon;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to station.{id} when a station note is updated.
 */
class StationNoteUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Station $station,
        public string $message,
        public string $authorName,
        public ?Carbon $updatedAt = null
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('station.'.$this->station->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'StationNoteUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
            'author_name' => $this->authorName,
            'updated_at' => $this->updatedAt?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }
}
