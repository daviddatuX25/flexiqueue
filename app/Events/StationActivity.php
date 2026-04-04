<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Per plan: broadcast station activity to display.activity.{programId} and display.station.{id} for real-time activity feed.
 * Per central-edge-v2-final Phase A: display.activity → display.activity.{programId}; display.station.{id} unchanged.
 */
class StationActivity implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $programId,
        public int $stationId,
        public string $stationName,
        public string $message,
        public string $alias,
        public string $actionType,
        public string $createdAt,
        public string $pronounceAs = 'letters',
        public ?int $tokenId = null,
        /** @var array<string, string>|null  en/fil/ilo → spoken token body for display TTS fallback */
        public ?array $tokenSpokenByLang = null
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('display.activity.'.$this->programId),
            new Channel('display.station.'.$this->stationId),
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
            'pronounce_as' => $this->pronounceAs,
            'token_id' => $this->tokenId,
            'token_spoken_by_lang' => $this->tokenSpokenByLang,
        ];
    }
}
