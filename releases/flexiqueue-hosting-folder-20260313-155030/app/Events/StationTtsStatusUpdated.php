<?php

namespace App\Events;

use App\Models\Station;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StationTtsStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Station $station) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.station-tts')];
    }

    public function broadcastAs(): string
    {
        return 'station_tts_status_updated';
    }

    public function broadcastWith(): array
    {
        return [
            'station_id' => $this->station->id,
            'settings' => $this->station->settings,
        ];
    }
}
