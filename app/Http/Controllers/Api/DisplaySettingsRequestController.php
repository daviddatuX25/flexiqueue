<?php

namespace App\Http\Controllers\Api;

use App\Events\DisplaySettingsUpdated;
use App\Http\Controllers\Controller;
use App\Models\DisplaySettingsRequest;
use App\Models\Program;
use App\Support\ProgramSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authenticated: approve display settings requests (e.g. when supervisor scans QR on Program Overrides).
 */
class DisplaySettingsRequestController extends Controller
{
    public function approve(Request $request, DisplaySettingsRequest $display_settings_request): JsonResponse
    {
        $user = $request->user();
        $program = $display_settings_request->program;
        if (! $program || ! $program->is_active) {
            return response()->json(['message' => 'Program not found or inactive.'], 400);
        }

        $canApprove = ($user->isAdmin() && $user->site_id === $program->site_id)
            || $user->isSupervisorForProgram($program->id);
        if (! $canApprove) {
            return response()->json(['message' => 'You may only approve display settings for your program or site.'], 403);
        }

        $token = $request->input('request_token');
        if (! $token || ! hash_equals($display_settings_request->request_token ?? '', $token)) {
            return response()->json(['message' => 'Invalid or expired QR.'], 403);
        }

        if (! $display_settings_request->isPending()) {
            return response()->json(['message' => 'Request already handled.'], 409);
        }

        $payload = $display_settings_request->settings_payload ?? [];
        $settings = $program->settings ?? [];
        foreach (['display_audio_muted', 'display_audio_volume', 'enable_display_hid_barcode', 'enable_public_triage_hid_barcode', 'enable_display_camera_scanner', 'enable_public_triage_camera_scanner', 'kiosk_hid_persistent_when_scan_modal_closed'] as $key) {
            if (array_key_exists($key, $payload)) {
                $settings[$key] = $payload[$key];
            }
        }
        $settings = ProgramSettings::syncKioskKeysToLegacyAliases($settings);
        $program->update(['settings' => $settings]);
        $program = $program->fresh();

        $display_settings_request->update([
            'status' => DisplaySettingsRequest::STATUS_APPROVED,
            'responded_by_user_id' => $user->id,
            'responded_at' => now(),
        ]);

        event(new DisplaySettingsUpdated(
            $program->id,
            $program->settings()->getDisplayAudioMuted(),
            $program->settings()->getDisplayAudioVolume(),
            $program->settings()->getEnableDisplayHidBarcode(),
            $program->settings()->getEnablePublicTriageHidBarcode(),
            $program->settings()->getEnableDisplayCameraScanner(),
            $program->settings()->getDisplayTtsRepeatCount(),
            $program->settings()->getDisplayTtsRepeatDelayMs(),
            $program->settings()->getEnablePublicTriageCameraScanner(),
            $program->settings()->getKioskHidPersistentWhenScanModalClosed(),
        ));

        return response()->json(['message' => 'Display settings updated.']);
    }
}
