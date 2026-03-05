<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Per plan: broadcast display audio and HID settings to display.activity so the general display board
 * and public triage can update in real time when admin or PIN-verified user changes them.
 */
class DisplaySettingsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public bool $displayAudioMuted,
        public float $displayAudioVolume,
        public bool $enableDisplayHidBarcode = true,
        public bool $enablePublicTriageHidBarcode = true,
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
        return 'display_settings';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'display_audio_muted' => $this->displayAudioMuted,
            'display_audio_volume' => $this->displayAudioVolume,
            'enable_display_hid_barcode' => $this->enableDisplayHidBarcode,
            'enable_public_triage_hid_barcode' => $this->enablePublicTriageHidBarcode,
        ];
    }
}
