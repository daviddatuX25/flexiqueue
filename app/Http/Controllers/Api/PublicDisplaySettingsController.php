<?php

namespace App\Http\Controllers\Api;

use App\Events\DisplaySettingsUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePublicDisplaySettingsRequest;
use App\Models\Program;
use App\Services\PublicDisplaySettingsAuthService;
use Illuminate\Http\JsonResponse;

/**
 * Per plan: public display/triage settings — verify supervisor/admin authorization and update program settings.
 * No auth; rate-limited by IP.
 */
class PublicDisplaySettingsController extends Controller
{
    public function __construct(
        private PublicDisplaySettingsAuthService $authService
    ) {}

    /**
     * Update display and triage settings. Authorization must be from an admin or supervisor of the program.
     * Per central-edge Phase A: program_id required in request; no single-active.
     */
    public function update(UpdatePublicDisplaySettingsRequest $request): JsonResponse
    {
        $program = Program::find((int) $request->validated('program_id'));

        if (! $program || ! $program->is_active) {
            return response()->json(['message' => 'Program not found or inactive.'], 400);
        }

        $auth = $this->authService->verify($request->validated(), $program, $request);
        if (! $auth->isOk()) {
            return match ($auth->code()) {
                'missing_auth_type', 'invalid_auth_type' => response()->json(['message' => 'Supervisor authorization required.'], 401),
                'rate_limited' => response()->json(['message' => 'Too many attempts. Try again in 15 minutes.'], 429),
                'expired_temp' => response()->json(['message' => 'Authorization expired. Request a new one.'], 401),
                'unauthorized_program' => response()->json(['message' => 'You are not a supervisor for this program. Preset authorization cannot be used here.'], 403),
                default => response()->json(['message' => 'Invalid PIN.'], 401),
            };
        }

        $settings = $program->settings ?? [];
        $payload = $request->validated();
        unset(
            $payload['pin'],
            $payload['auth_type'],
            $payload['supervisor_pin'],
            $payload['qr_scan_token'],
            $payload['temp_code']
        );

        foreach (['display_audio_muted', 'display_audio_volume', 'enable_display_hid_barcode', 'enable_public_triage_hid_barcode', 'enable_display_camera_scanner', 'enable_public_triage_camera_scanner'] as $key) {
            if (array_key_exists($key, $payload)) {
                $settings[$key] = $payload[$key];
            }
        }

        $program->update(['settings' => $settings]);
        $program = $program->fresh();

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
        ));

        return response()->json([
            'display_audio_muted' => $program->settings()->getDisplayAudioMuted(),
            'display_audio_volume' => $program->settings()->getDisplayAudioVolume(),
            'enable_display_hid_barcode' => $program->settings()->getEnableDisplayHidBarcode(),
            'enable_public_triage_hid_barcode' => $program->settings()->getEnablePublicTriageHidBarcode(),
            'enable_display_camera_scanner' => $program->settings()->getEnableDisplayCameraScanner(),
            'enable_public_triage_camera_scanner' => $program->settings()->getEnablePublicTriageCameraScanner(),
        ]);
    }
}
