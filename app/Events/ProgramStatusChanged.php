<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast program paused/resumed/closed to display.activity.{programId} so the public
 * display board can show "Program is paused" or "Program is not currently running."
 * Per central-edge-v2-final Phase A: program-scoped channel only.
 * Per plan Step 5: program_is_active false when program is closed (deactivated).
 */
class ProgramStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $programId,
        public bool $programIsPaused,
        public bool $programIsActive = true
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
        return 'program_status';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'program_is_paused' => $this->programIsPaused,
            'program_is_active' => $this->programIsActive,
        ];
    }
}
