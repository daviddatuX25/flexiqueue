<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Per plan: broadcast station display audio settings to display.station.{id}
 * so the station display page updates mute/volume in real time when staff change them on /station/*.
 */
class StationDisplaySettingsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $stationId,
        public bool $displayAudioMuted,
        public float $displayAudioVolume,
        public ?string $displayTtsVoice = null
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('display.station.'.$this->stationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'display_station_settings';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'display_audio_muted' => $this->displayAudioMuted,
            'display_audio_volume' => $this->displayAudioVolume,
            'display_tts_voice' => $this->displayTtsVoice,
        ];
    }
}
