<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast program paused/resumed to display.activity.{programId} so the public
 * display board can show or hide the "Program is paused" overlay in real time.
 * Per central-edge-v2-final Phase A: program-scoped channel only.
 */
class ProgramStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $programId,
        public bool $programIsPaused
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
        ];
    }
}
