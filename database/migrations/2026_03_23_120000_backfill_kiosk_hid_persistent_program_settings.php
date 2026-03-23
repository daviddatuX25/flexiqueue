<?php

use App\Models\Program;
use Illuminate\Database\Migrations\Migration;

/**
 * Idempotent: persist kiosk_hid_persistent_when_scan_modal_closed from legacy key when missing.
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
                if (array_key_exists('kiosk_hid_persistent_when_scan_modal_closed', $s)) {
                    continue;
                }
                $legacy = (bool) ($s['enable_public_triage_hid_persistent_when_scan_modal_closed'] ?? false);
                $merged = $s;
                $merged['kiosk_hid_persistent_when_scan_modal_closed'] = $legacy;
                $merged['enable_public_triage_hid_persistent_when_scan_modal_closed'] = $legacy;
                $program->update(['settings' => $merged]);
            }
        });
    }

    public function down(): void
    {
        // Non-destructive: do not strip kiosk_* keys.
    }
};
