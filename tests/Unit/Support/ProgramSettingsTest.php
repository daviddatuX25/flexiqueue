<?php

namespace Tests\Unit\Support;

use App\Support\ProgramSettings;
use PHPUnit\Framework\TestCase;

class ProgramSettingsTest extends TestCase
{
    public function test_defaults_are_applied_when_empty(): void
    {
        $s = ProgramSettings::fromArray([]);

        $this->assertSame(10, $s->getNoShowTimerSeconds());
        $this->assertSame(3, $s->getMaxNoShowAttempts());
        $this->assertTrue($s->getRequirePermissionBeforeOverride());
        $this->assertTrue($s->getPriorityFirst());
        $this->assertSame('fifo', $s->getBalanceMode());
        $this->assertSame([1, 1], $s->getAlternateRatio());
        $this->assertSame('fixed', $s->getStationSelectionMode());
        $this->assertSame(20, $s->getDisplayScanTimeoutSeconds());
        $this->assertFalse($s->getDisplayAudioMuted());
        $this->assertSame(1.0, $s->getDisplayAudioVolume());
        $this->assertSame(1, $s->getDisplayTtsRepeatCount());
        $this->assertSame(2000, $s->getDisplayTtsRepeatDelayMs());
        $this->assertFalse($s->getAllowPublicTriage());
        $this->assertTrue($s->getEnableDisplayHidBarcode());
        $this->assertTrue($s->getEnablePublicTriageHidBarcode());
        $this->assertTrue($s->getEnableDisplayCameraScanner());
        $this->assertSame('en', $s->getTtsActiveLanguage());
    }

    public function test_enable_display_camera_scanner_defaults_true_and_respects_setting(): void
    {
        $this->assertTrue(ProgramSettings::fromArray([])->getEnableDisplayCameraScanner());
        $this->assertTrue(ProgramSettings::fromArray(['enable_display_camera_scanner' => true])->getEnableDisplayCameraScanner());
        $this->assertFalse(ProgramSettings::fromArray(['enable_display_camera_scanner' => false])->getEnableDisplayCameraScanner());
    }

    public function test_balance_mode_is_validated(): void
    {
        $this->assertSame('fifo', ProgramSettings::fromArray(['balance_mode' => 'bogus'])->getBalanceMode());
        $this->assertSame('alternate', ProgramSettings::fromArray(['balance_mode' => 'alternate'])->getBalanceMode());
    }

    public function test_station_selection_mode_is_validated(): void
    {
        $this->assertSame('fixed', ProgramSettings::fromArray(['station_selection_mode' => 'bogus'])->getStationSelectionMode());
        $this->assertSame('least_busy', ProgramSettings::fromArray(['station_selection_mode' => 'least_busy'])->getStationSelectionMode());
    }

    public function test_display_audio_volume_is_clamped_between_zero_and_one(): void
    {
        $this->assertSame(0.0, ProgramSettings::fromArray(['display_audio_volume' => -5])->getDisplayAudioVolume());
        $this->assertSame(1.0, ProgramSettings::fromArray(['display_audio_volume' => 5])->getDisplayAudioVolume());
        $this->assertSame(0.5, ProgramSettings::fromArray(['display_audio_volume' => 0.5])->getDisplayAudioVolume());
    }

    public function test_display_tts_repeat_count_is_clamped_one_to_three(): void
    {
        $this->assertSame(1, ProgramSettings::fromArray(['display_tts_repeat_count' => 0])->getDisplayTtsRepeatCount());
        $this->assertSame(3, ProgramSettings::fromArray(['display_tts_repeat_count' => 99])->getDisplayTtsRepeatCount());
        $this->assertSame(2, ProgramSettings::fromArray(['display_tts_repeat_count' => 2])->getDisplayTtsRepeatCount());
    }

    public function test_display_tts_repeat_delay_is_clamped(): void
    {
        $this->assertSame(500, ProgramSettings::fromArray(['display_tts_repeat_delay_ms' => 0])->getDisplayTtsRepeatDelayMs());
        $this->assertSame(10000, ProgramSettings::fromArray(['display_tts_repeat_delay_ms' => 999999])->getDisplayTtsRepeatDelayMs());
        $this->assertSame(3000, ProgramSettings::fromArray(['display_tts_repeat_delay_ms' => 3000])->getDisplayTtsRepeatDelayMs());
    }

    public function test_alternate_ratio_is_sanitized(): void
    {
        $this->assertSame([1, 1], ProgramSettings::fromArray(['alternate_ratio' => 'nope'])->getAlternateRatio());
        $this->assertSame([1, 1], ProgramSettings::fromArray(['alternate_ratio' => []])->getAlternateRatio());
        $this->assertSame([1, 1], ProgramSettings::fromArray(['alternate_ratio' => [0, 0]])->getAlternateRatio());
        $this->assertSame([2, 1], ProgramSettings::fromArray(['alternate_ratio' => [2, 1]])->getAlternateRatio());
    }

    public function test_tts_active_language_is_validated(): void
    {
        $this->assertSame('en', ProgramSettings::fromArray(['tts' => ['active_language' => 'xx']])->getTtsActiveLanguage());
        $this->assertSame('fil', ProgramSettings::fromArray(['tts' => ['active_language' => 'fil']])->getTtsActiveLanguage());
    }

    public function test_max_no_show_attempts_defaults_and_clamped(): void
    {
        $this->assertSame(3, ProgramSettings::fromArray([])->getMaxNoShowAttempts());
        $this->assertSame(5, ProgramSettings::fromArray(['max_no_show_attempts' => 5])->getMaxNoShowAttempts());
        $this->assertSame(1, ProgramSettings::fromArray(['max_no_show_attempts' => 0])->getMaxNoShowAttempts());
        $this->assertSame(10, ProgramSettings::fromArray(['max_no_show_attempts' => 99])->getMaxNoShowAttempts());
    }

    /** Per IDENTITY-BINDING-FINAL-IMPLEMENTATION-PLAN: only disabled | required; optional washed out. */
    public function test_identity_binding_mode_defaults_and_validation(): void
    {
        $defaults = ProgramSettings::fromArray([]);
        $this->assertSame('disabled', $defaults->getIdentityBindingMode());
        $this->assertTrue($defaults->isBindingDisabled());
        $this->assertFalse($defaults->isBindingRequired());

        $required = ProgramSettings::fromArray(['identity_binding_mode' => 'required']);
        $this->assertSame('required', $required->getIdentityBindingMode());
        $this->assertFalse($required->isBindingDisabled());
        $this->assertTrue($required->isBindingRequired());

        $invalid = ProgramSettings::fromArray(['identity_binding_mode' => 'bogus']);
        $this->assertSame('disabled', $invalid->getIdentityBindingMode());
        $this->assertTrue($invalid->isBindingDisabled());

        $optionalTreatedAsDisabled = ProgramSettings::fromArray(['identity_binding_mode' => 'optional']);
        $this->assertSame('disabled', $optionalTreatedAsDisabled->getIdentityBindingMode());
    }

    public function test_public_binding_helpers_respect_matrix(): void
    {
        // Public triage disabled: both helpers false regardless of mode.
        $disabledPublic = ProgramSettings::fromArray([
            'allow_public_triage' => false,
            'identity_binding_mode' => 'required',
        ]);
        $this->assertFalse($disabledPublic->allowsPublicBinding());
        $this->assertFalse($disabledPublic->requiresPublicBinding());

        // Public triage enabled, binding disabled.
        $bindingDisabled = ProgramSettings::fromArray([
            'allow_public_triage' => true,
            'identity_binding_mode' => 'disabled',
        ]);
        $this->assertFalse($bindingDisabled->allowsPublicBinding());
        $this->assertFalse($bindingDisabled->requiresPublicBinding());

        // Public triage enabled, binding required (only mode that allows public binding per plan).
        $required = ProgramSettings::fromArray([
            'allow_public_triage' => true,
            'identity_binding_mode' => 'required',
        ]);
        $this->assertTrue($required->allowsPublicBinding());
        $this->assertTrue($required->requiresPublicBinding());
    }

    public function test_kiosk_getters_fallback_to_legacy_keys(): void
    {
        $legacy = ProgramSettings::fromArray([
            'allow_public_triage' => true,
            'enable_public_triage_hid_barcode' => false,
            'enable_public_triage_camera_scanner' => false,
            'display_scan_timeout_seconds' => 45,
        ]);
        $this->assertTrue($legacy->getKioskSelfServiceTriageEnabled());
        $this->assertTrue($legacy->getKioskStatusCheckerEnabled());
        $this->assertFalse(
            ProgramSettings::fromArray(['allow_public_triage' => false])->getKioskStatusCheckerEnabled()
        );
        $this->assertFalse($legacy->getKioskEnableHidBarcode());
        $this->assertFalse($legacy->getKioskEnableCameraScanner());
        $this->assertSame(45, $legacy->getKioskModalIdleSeconds());

        $explicit = ProgramSettings::fromArray([
            'allow_public_triage' => false,
            'kiosk_self_service_triage_enabled' => true,
            'kiosk_status_checker_enabled' => false,
            'kiosk_modal_idle_seconds' => 0,
        ]);
        $this->assertTrue($explicit->getKioskSelfServiceTriageEnabled());
        $this->assertFalse($explicit->getKioskStatusCheckerEnabled());
        $this->assertSame(0, $explicit->getKioskModalIdleSeconds());
    }
}
