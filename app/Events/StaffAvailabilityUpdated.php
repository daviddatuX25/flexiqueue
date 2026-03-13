<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Per flexiqueue-wrx: broadcast staff availability change to display.activity.{programId}
 * so the public display board can refresh staff list in real time.
 * Per central-edge-v2-final Phase A: program-scoped channel only. Omit broadcast when user has no assigned program.
 */
class StaffAvailabilityUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $programId,
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
            new Channel('display.activity.'.$this->programId),
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
