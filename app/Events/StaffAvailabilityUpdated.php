<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Per flexiqueue-wrx: broadcast staff availability change to display.activity
 * so the public display board can refresh staff list in real time.
 * Public channel; payload minimal (no sensitive data).
 */
class StaffAvailabilityUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $userId,
        public string $availabilityStatus,
        public string $name
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
        return 'staff_availability';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'availability_status' => $this->availabilityStatus,
            'name' => $this->name,
        ];
    }
}
