<?php

namespace App\Repositories;

use App\Support\ProgramSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Platform template (site_id null) + one row per site — same pattern as PrintSettingRepository.
 * Used by ProgramDefaultSettingsController (site), ProgramPlatformDefaultSettingsController (platform),
 * ProgramController::store, and SiteController::store (copy template).
 */
class ProgramDefaultSettingRepository
{
    /**
     * Normalized defaults from the platform row (site_id null), creating baseline if missing.
     */
    public function getPlatformTemplate(): array
    {
        $row = $this->getPlatformRow();
        if ($row === null) {
            $normalized = $this->normalizeSettings([]);
            $this->insertRow(null, $normalized);

            return $normalized;
        }

        $settings = json_decode($row->settings ?? '{}', true) ?? [];

        return $this->normalizeSettings($settings);
    }

    /**
     * Defaults for new programs in this site. Ensures a site row exists (copies platform if missing).
     */
    public function getNormalizedForSite(int $siteId): array
    {
        $row = DB::table('program_default_settings')->where('site_id', $siteId)->first();
        if ($row !== null) {
            $settings = json_decode($row->settings ?? '{}', true) ?? [];

            return $this->normalizeSettings($settings);
        }

        $normalized = $this->getPlatformTemplate();
        $this->persistForSite($siteId, $normalized);

        return $normalized;
    }

    /**
     * Copy platform JSON into a new site row (used when creating a site).
     */
    public function copyPlatformTemplateToSite(int $siteId): void
    {
        if (! Schema::hasTable('program_default_settings')) {
            return;
        }

        $existing = DB::table('program_default_settings')->where('site_id', $siteId)->first();
        if ($existing !== null) {
            return;
        }

        $normalized = $this->getPlatformTemplate();
        $this->insertRow($siteId, $normalized);
    }

    public function persistPlatformTemplate(array $normalized): void
    {
        $row = $this->getPlatformRow();
        $payload = json_encode($normalized);
        if ($row !== null) {
            DB::table('program_default_settings')
                ->where('id', $row->id)
                ->update(['settings' => $payload, 'updated_at' => now()]);
        } else {
            $this->insertRow(null, $normalized);
        }
    }

    public function persistForSite(int $siteId, array $normalized): void
    {
        $row = DB::table('program_default_settings')->where('site_id', $siteId)->first();
        $payload = json_encode($normalized);
        if ($row !== null) {
            DB::table('program_default_settings')
                ->where('id', $row->id)
                ->update(['settings' => $payload, 'updated_at' => now()]);
        } else {
            $this->insertRow($siteId, $normalized);
        }
    }

    /**
     * Normalize request payload (same as GET response shape).
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function normalizeSettings(array $settings): array
    {
        $programSettings = ProgramSettings::fromArray($settings);

        $normalized = [
            'no_show_timer_seconds' => $programSettings->getNoShowTimerSeconds(),
            'max_no_show_attempts' => $programSettings->getMaxNoShowAttempts(),
            'require_permission_before_override' => $programSettings->getRequirePermissionBeforeOverride(),
            'priority_first' => $programSettings->getPriorityFirst(),
            'balance_mode' => $programSettings->getBalanceMode(),
            'station_selection_mode' => $programSettings->getStationSelectionMode(),
            'alternate_ratio' => $programSettings->getAlternateRatio(),
            'alternate_priority_first' => array_key_exists('alternate_priority_first', $settings)
                ? (bool) $settings['alternate_priority_first']
                : true,
            'display_scan_timeout_seconds' => $programSettings->getDisplayScanTimeoutSeconds(),
            'display_audio_muted' => $programSettings->getDisplayAudioMuted(),
            'display_audio_volume' => $programSettings->getDisplayAudioVolume(),
            'display_tts_repeat_count' => $programSettings->getDisplayTtsRepeatCount(),
            'display_tts_repeat_delay_ms' => $programSettings->getDisplayTtsRepeatDelayMs(),
            'allow_public_triage' => $programSettings->getKioskSelfServiceTriageEnabled(),
            'kiosk_self_service_triage_enabled' => $programSettings->getKioskSelfServiceTriageEnabled(),
            'kiosk_status_checker_enabled' => $programSettings->getKioskStatusCheckerEnabled(),
            'kiosk_enable_hid_barcode' => $programSettings->getKioskEnableHidBarcode(),
            'kiosk_enable_camera_scanner' => $programSettings->getKioskEnableCameraScanner(),
            'kiosk_modal_idle_seconds' => $programSettings->getKioskModalIdleSeconds(),
            'allow_unverified_entry' => $programSettings->getAllowUnverifiedEntry(),
            'identity_binding_mode' => $programSettings->getIdentityBindingMode(),
            'enable_display_hid_barcode' => $programSettings->getEnableDisplayHidBarcode(),
            'enable_public_triage_hid_barcode' => $programSettings->getEnablePublicTriageHidBarcode(),
            'enable_display_camera_scanner' => $programSettings->getEnableDisplayCameraScanner(),
            'enable_public_triage_camera_scanner' => $programSettings->getEnablePublicTriageCameraScanner(),
            'tts' => $this->normalizeTtsSettings($settings['tts'] ?? null, $programSettings),
        ];

        return $normalized;
    }

    private function getPlatformRow(): ?object
    {
        if (! Schema::hasTable('program_default_settings')) {
            return null;
        }

        $rows = DB::table('program_default_settings')->whereNull('site_id')->orderBy('id')->get();
        if ($rows->count() > 1) {
            Log::warning('Multiple program_default_settings rows with site_id null; using oldest id.', [
                'ids' => $rows->pluck('id')->all(),
            ]);
        }

        return $rows->first();
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function insertRow(?int $siteId, array $normalized): void
    {
        DB::table('program_default_settings')->insert([
            'site_id' => $siteId,
            'settings' => json_encode($normalized),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function normalizeTtsSettings($rawTts, ProgramSettings $programSettings): array
    {
        $tts = is_array($rawTts) ? $rawTts : [];

        $normalized = [
            'active_language' => $programSettings->getTtsActiveLanguage(),
        ];

        if (array_key_exists('auto_generate_station_tts', $tts)) {
            $normalized['auto_generate_station_tts'] = (bool) $tts['auto_generate_station_tts'];
        }

        if (isset($tts['connector']) && is_array($tts['connector'])) {
            $connector = $tts['connector'];
            $languages = $connector['languages'] ?? null;

            if (is_array($languages)) {
                $normalizedLanguages = [];

                foreach (['en', 'fil', 'ilo'] as $lang) {
                    if (! isset($languages[$lang]) || ! is_array($languages[$lang])) {
                        continue;
                    }

                    $langConfig = $languages[$lang];
                    $normalizedLanguages[$lang] = array_intersect_key(
                        $langConfig,
                        array_flip(['voice_id', 'rate', 'connector_phrase']),
                    );
                }

                if ($normalizedLanguages !== []) {
                    $normalized['connector'] = [
                        'languages' => $normalizedLanguages,
                    ];
                }
            }
        }

        return $normalized;
    }
}
