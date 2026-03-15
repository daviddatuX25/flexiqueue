<?php

namespace App\Events;

use App\Models\Session;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Per 08-API-SPEC-PHASE1 §7.1: broadcast to station.{id} when a client arrives (bind or transfer).
 */
class ClientArrived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Session $session,
        public int $stationId
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('station.'.$this->stationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'client_arrived';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'alias' => $this->session->alias,
            'category' => $this->session->client_category,
            'track' => $this->session->serviceTrack?->name,
        ];
    }
}
