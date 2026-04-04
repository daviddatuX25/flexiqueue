<?php

use App\Models\Program;
use Illuminate\Database\Migrations\Migration;

/**
 * One-time: persist canonical kiosk_* keys from legacy program settings JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Program::query()->orderBy('id')->chunk(100, function ($programs): void {
            foreach ($programs as $program) {
                $s = $program->settings ?? [];
                if (! is_array($s)) {
                    continue;
                }
                if (array_key_exists('kiosk_self_service_triage_enabled', $s) && array_key_exists('kiosk_status_checker_enabled', $s)) {
                    continue;
                }
                $merged = $s;
                $allow = (bool) ($s['allow_public_triage'] ?? false);
                if (! array_key_exists('kiosk_self_service_triage_enabled', $merged)) {
                    $merged['kiosk_self_service_triage_enabled'] = $allow;
                }
                if (! array_key_exists('kiosk_status_checker_enabled', $merged)) {
                    $merged['kiosk_status_checker_enabled'] = $allow;
                }
                if (! array_key_exists('kiosk_enable_hid_barcode', $merged)) {
                    $merged['kiosk_enable_hid_barcode'] = (bool) ($s['enable_public_triage_hid_barcode'] ?? true);
                }
                if (! array_key_exists('kiosk_enable_camera_scanner', $merged)) {
                    $merged['kiosk_enable_camera_scanner'] = (bool) ($s['enable_public_triage_camera_scanner'] ?? true);
                }
                if (! array_key_exists('kiosk_modal_idle_seconds', $merged)) {
                    $merged['kiosk_modal_idle_seconds'] = (int) ($s['display_scan_timeout_seconds'] ?? 20);
                }
                $program->update(['settings' => $merged]);
            }
        });
    }

    public function down(): void
    {
        // Non-destructive: do not strip kiosk_* keys.
    }
};
