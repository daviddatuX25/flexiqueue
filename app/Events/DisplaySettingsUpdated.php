<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Per plan: broadcast display audio and HID settings to display.activity.{programId} so the general display board
 * and public triage can update in real time when admin or PIN-verified user changes them.
 * Per central-edge-v2-final Phase A: program-scoped channel only.
 */
class DisplaySettingsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $programId,
        public bool $displayAudioMuted,
        public float $displayAudioVolume,
        public bool $enableDisplayHidBarcode = true,
        public bool $enablePublicTriageHidBarcode = true,
        public bool $enableDisplayCameraScanner = true,
        public int $displayTtsRepeatCount = 1,
        public int $displayTtsRepeatDelayMs = 2000,
        public bool $enablePublicTriageCameraScanner = true,
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
            'enable_display_camera_scanner' => $this->enableDisplayCameraScanner,
            'enable_public_triage_camera_scanner' => $this->enablePublicTriageCameraScanner,
            'display_tts_repeat_count' => $this->displayTtsRepeatCount,
            'display_tts_repeat_delay_ms' => $this->displayTtsRepeatDelayMs,
        ];
    }
}
