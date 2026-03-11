<?php

namespace App\Support;

final class ProgramSettings
{
    private function __construct(
        private readonly array $settings,
    ) {
    }

    public static function fromArray(array $settings): self
    {
        return new self($settings);
    }

    public function getNoShowTimerSeconds(): int
    {
        return (int) ($this->settings['no_show_timer_seconds'] ?? 10);
    }

    /** Per flexiqueue-a3wh: max no-show attempts before staff must choose extend or last call. Default 3, min 1, max 10. */
    public function getMaxNoShowAttempts(): int
    {
        $v = $this->settings['max_no_show_attempts'] ?? 3;

        return (int) max(1, min(10, (int) $v));
    }

    public function getRequirePermissionBeforeOverride(): bool
    {
        return (bool) ($this->settings['require_permission_before_override'] ?? true);
    }

    public function getPriorityFirst(): bool
    {
        return (bool) ($this->settings['priority_first'] ?? true);
    }

    public function getBalanceMode(): string
    {
        $mode = $this->settings['balance_mode'] ?? 'fifo';

        return in_array($mode, ['fifo', 'alternate'], true) ? $mode : 'fifo';
    }

    /**
     * @return array{0: int, 1: int} [priority_count, regular_count] e.g. [2, 1] = 2 priority per 1 regular
     */
    public function getAlternateRatio(): array
    {
        $ratio = $this->settings['alternate_ratio'] ?? [1, 1];
        if (! is_array($ratio) || count($ratio) < 2) {
            return [1, 1];
        }

        $p = max(1, (int) ($ratio[0] ?? 1));
        $r = max(1, (int) ($ratio[1] ?? 1));

        return [$p, $r];
    }

    public function getStationSelectionMode(): string
    {
        $mode = $this->settings['station_selection_mode'] ?? 'fixed';

        return in_array($mode, ['fixed', 'shortest_queue', 'least_busy', 'round_robin', 'least_recently_served'], true)
            ? $mode
            : 'fixed';
    }

    /** Per flexiqueue-87p: display board scan auto-close. 0 = no auto-close; default 20 seconds. */
    public function getDisplayScanTimeoutSeconds(): int
    {
        $v = $this->settings['display_scan_timeout_seconds'] ?? null;

        return $v === null ? 20 : max(0, (int) $v);
    }

    /** Per plan: display board audio mute (admin-controlled). Default false. */
    public function getDisplayAudioMuted(): bool
    {
        return (bool) ($this->settings['display_audio_muted'] ?? false);
    }

    /** Per plan: display board audio volume 0–1 (admin-controlled). Default 1. */
    public function getDisplayAudioVolume(): float
    {
        $v = $this->settings['display_audio_volume'] ?? 1;

        return (float) max(0, min(1, $v));
    }

    /** Display TTS announcement repeat count (1–3: Once, Twice, Three times). Default 1. */
    public function getDisplayTtsRepeatCount(): int
    {
        $v = $this->settings['display_tts_repeat_count'] ?? 1;

        return (int) max(1, min(3, $v));
    }

    /** Delay between repeated announcements in milliseconds (500–10000). Default 2000. */
    public function getDisplayTtsRepeatDelayMs(): int
    {
        $v = $this->settings['display_tts_repeat_delay_ms'] ?? 2000;

        return (int) max(500, min(10000, $v));
    }

    /** Per plan: allow public self-serve triage at GET /triage/start. Default false. */
    public function getAllowPublicTriage(): bool
    {
        return (bool) ($this->settings['allow_public_triage'] ?? false);
    }

    /** Per barcode-hid plan: enable HID barcode input on Display board. Default true. */
    public function getEnableDisplayHidBarcode(): bool
    {
        return (bool) ($this->settings['enable_display_hid_barcode'] ?? true);
    }

    /** Per barcode-hid plan: enable HID barcode input on Public triage. Default true. */
    public function getEnablePublicTriageHidBarcode(): bool
    {
        return (bool) ($this->settings['enable_public_triage_hid_barcode'] ?? true);
    }

    /** Per plan: enable camera/QR scanner on Display board. Default true. */
    public function getEnableDisplayCameraScanner(): bool
    {
        return (bool) ($this->settings['enable_display_camera_scanner'] ?? true);
    }

    /**
     * Active TTS language for this program (used by displays and generation).
     * Defaults to 'en' when not explicitly configured.
     */
    public function getTtsActiveLanguage(): string
    {
        $lang = $this->settings['tts']['active_language'] ?? 'en';
        $allowed = ['en', 'fil', 'ilo'];

        return in_array($lang, $allowed, true) ? $lang : 'en';
    }
}

