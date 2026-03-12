<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProgramDefaultSettingsRequest;
use App\Support\ProgramSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per ISSUES-ELABORATION §2: global default program settings (GET/PUT). Admin only.
 */
class ProgramDefaultSettingsController extends Controller
{
    private const ROW_ID = 1;

    /**
     * GET /api/admin/program-default-settings — Return current default settings (same shape as program.settings).
     */
    public function show(): JsonResponse
    {
        $row = $this->getRow();
        $settings = $row ? (json_decode($row->settings ?? '{}', true) ?? []) : [];

        return response()->json([
            'settings' => $this->normalizeSettings($settings),
        ]);
    }

    /**
     * PUT /api/admin/program-default-settings — Save default settings.
     */
    public function update(UpdateProgramDefaultSettingsRequest $request): JsonResponse
    {
        $settings = $request->validated()['settings'];
        $settings = $this->normalizeSettings($settings);

        $row = $this->getRow();
        if ($row) {
            DB::table('program_default_settings')
                ->where('id', self::ROW_ID)
                ->update(['settings' => json_encode($settings), 'updated_at' => now()]);
        } else {
            DB::table('program_default_settings')->insert([
                'id' => self::ROW_ID,
                'settings' => json_encode($settings),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['settings' => $settings]);
    }

    private function getRow(): ?object
    {
        if (! Schema::hasTable('program_default_settings')) {
            return null;
        }

        return DB::table('program_default_settings')->find(self::ROW_ID);
    }

    private function normalizeSettings(array $settings): array
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
            // When not explicitly set, default to true so alternate mode starts with priority lane first.
            'alternate_priority_first' => array_key_exists('alternate_priority_first', $settings)
                ? (bool) $settings['alternate_priority_first']
                : true,
            'display_scan_timeout_seconds' => $programSettings->getDisplayScanTimeoutSeconds(),
            'display_audio_muted' => $programSettings->getDisplayAudioMuted(),
            'display_audio_volume' => $programSettings->getDisplayAudioVolume(),
            'display_tts_repeat_count' => $programSettings->getDisplayTtsRepeatCount(),
            'display_tts_repeat_delay_ms' => $programSettings->getDisplayTtsRepeatDelayMs(),
            'allow_public_triage' => $programSettings->getAllowPublicTriage(),
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
