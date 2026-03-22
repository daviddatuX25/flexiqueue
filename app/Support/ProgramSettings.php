<?php

namespace App\Support;

final class ProgramSettings
{
    private function __construct(
        private readonly array $settings,
    ) {}

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

    /** Per plan: allow public self-serve triage at GET /public-triage. Default false. */
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

    /** Per plan: enable camera/QR scanner on Public triage. Default true. */
    public function getEnablePublicTriageCameraScanner(): bool
    {
        return (bool) ($this->settings['enable_public_triage_camera_scanner'] ?? true);
    }

    /**
     * Kiosk self-service triage (canonical `kiosk_*`; legacy `allow_public_triage`).
     *
     * @see docs/plans/DEVICE_REFACTOR_KIOSK_QR_MODULARITY_PLAN.md §6.3
     */
    public function getKioskSelfServiceTriageEnabled(): bool
    {
        if (array_key_exists('kiosk_self_service_triage_enabled', $this->settings)) {
            return (bool) $this->settings['kiosk_self_service_triage_enabled'];
        }

        return $this->getAllowPublicTriage();
    }

    /**
     * Kiosk status checker when token already in queue. If no explicit `kiosk_status_checker_enabled`,
     * mirror legacy `allow_public_triage` so existing programs keep the same token-lookup availability.
     */
    public function getKioskStatusCheckerEnabled(): bool
    {
        if (array_key_exists('kiosk_status_checker_enabled', $this->settings)) {
            return (bool) $this->settings['kiosk_status_checker_enabled'];
        }

        return $this->getAllowPublicTriage();
    }

    /** True when any kiosk feature is enabled (self-service triage and/or status checker). */
    public function getKioskSurfaceEnabled(): bool
    {
        return $this->getKioskSelfServiceTriageEnabled() || $this->getKioskStatusCheckerEnabled();
    }

    /**
     * After merging incoming settings, mirror canonical `kiosk_*` keys to legacy aliases so raw JSON readers stay aligned.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function syncKioskKeysToLegacyAliases(array $settings): array
    {
        if (array_key_exists('kiosk_self_service_triage_enabled', $settings)) {
            $settings['allow_public_triage'] = (bool) $settings['kiosk_self_service_triage_enabled'];
        }
        if (array_key_exists('kiosk_enable_hid_barcode', $settings)) {
            $settings['enable_public_triage_hid_barcode'] = (bool) $settings['kiosk_enable_hid_barcode'];
        }
        if (array_key_exists('kiosk_enable_camera_scanner', $settings)) {
            $settings['enable_public_triage_camera_scanner'] = (bool) $settings['kiosk_enable_camera_scanner'];
        }
        if (array_key_exists('kiosk_hid_persistent_when_scan_modal_closed', $settings)) {
            $settings['enable_public_triage_hid_persistent_when_scan_modal_closed'] = (bool) $settings['kiosk_hid_persistent_when_scan_modal_closed'];
        }

        return $settings;
    }

    public function getKioskEnableHidBarcode(): bool
    {
        if (array_key_exists('kiosk_enable_hid_barcode', $this->settings)) {
            return (bool) $this->settings['kiosk_enable_hid_barcode'];
        }

        return $this->getEnablePublicTriageHidBarcode();
    }

    public function getKioskEnableCameraScanner(): bool
    {
        if (array_key_exists('kiosk_enable_camera_scanner', $this->settings)) {
            return (bool) $this->settings['kiosk_enable_camera_scanner'];
        }

        return $this->getEnablePublicTriageCameraScanner();
    }

    /** Modal auto-close on kiosk; falls back to display scan timeout then 20s. */
    public function getKioskModalIdleSeconds(): int
    {
        if (array_key_exists('kiosk_modal_idle_seconds', $this->settings)) {
            return max(0, (int) $this->settings['kiosk_modal_idle_seconds']);
        }

        return $this->getDisplayScanTimeoutSeconds();
    }

    /**
     * When true, kiosk HID stays refocused when the scan modal is closed (program default for devices with no local override).
     */
    public function getKioskHidPersistentWhenScanModalClosed(): bool
    {
        if (array_key_exists('kiosk_hid_persistent_when_scan_modal_closed', $this->settings)) {
            return (bool) $this->settings['kiosk_hid_persistent_when_scan_modal_closed'];
        }
        if (array_key_exists('enable_public_triage_hid_persistent_when_scan_modal_closed', $this->settings)) {
            return (bool) $this->settings['enable_public_triage_hid_persistent_when_scan_modal_closed'];
        }

        return false;
    }

    /** Per IDENTITY-BINDING-FINAL-IMPLEMENTATION-PLAN: only disabled | required; optional washed out. */
    public function getIdentityBindingMode(): string
    {
        $mode = $this->settings['identity_binding_mode'] ?? 'disabled';
        $allowed = ['disabled', 'required'];

        return in_array($mode, $allowed, true) ? $mode : 'disabled';
    }

    public function isBindingDisabled(): bool
    {
        return $this->getIdentityBindingMode() === 'disabled';
    }

    public function isBindingRequired(): bool
    {
        return $this->getIdentityBindingMode() === 'required';
    }

    /** When true, public triage can bind token (with or without identity). Only for mode 'required'. */
    public function allowsPublicBinding(): bool
    {
        if (! $this->getKioskSelfServiceTriageEnabled()) {
            return false;
        }

        return $this->isBindingRequired();
    }

    public function requiresPublicBinding(): bool
    {
        if (! $this->getKioskSelfServiceTriageEnabled()) {
            return false;
        }

        return $this->isBindingRequired();
    }

    /** Per plan: when true, public triage can create a session with identity registration (unverified). When false, only registration is created, no session. */
    public function getAllowUnverifiedEntry(): bool
    {
        return (bool) ($this->settings['allow_unverified_entry'] ?? false);
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

    /** Per addition-to-public-site-plan: program key for private program access. Null = public within site. Always returns trimmed string when set. */
    public function getPublicAccessKey(): ?string
    {
        $v = $this->settings['public_access_key'] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    /** Per addition-to-public-site-plan: hours until program-access cookie expires. Min 1, max 168 (1 week). */
    public function getPublicAccessExpiryHours(): int
    {
        $v = $this->settings['public_access_expiry_hours'] ?? 24;

        return (int) max(1, min(168, (int) $v));
    }

    /** Per addition-to-public-site-plan: short public-facing description on program info page. */
    public function getPageDescription(): ?string
    {
        $v = $this->settings['page_description'] ?? null;

        return $v === null || $v === '' ? null : (string) $v;
    }

    /** Per addition-to-public-site-plan: ephemeral notice on program info page. */
    public function getPageAnnouncement(): ?string
    {
        $v = $this->settings['page_announcement'] ?? null;

        return $v === null || $v === '' ? null : (string) $v;
    }

    /** Per addition-to-public-site-plan: true when program requires key entry (public_access_key set). */
    public function isPrivate(): bool
    {
        return $this->getPublicAccessKey() !== null;
    }
}
