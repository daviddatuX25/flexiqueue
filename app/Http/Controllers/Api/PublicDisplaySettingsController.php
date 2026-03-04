<?php

namespace App\Http\Controllers\Api;

use App\Events\DisplaySettingsUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePublicDisplaySettingsRequest;
use App\Models\Program;
use App\Services\PinService;
use Illuminate\Http\JsonResponse;

/**
 * Per plan: public display/triage settings — verify supervisor/admin PIN and update program settings.
 * No auth; rate-limited by IP.
 */
class PublicDisplaySettingsController extends Controller
{
    public function __construct(
        private PinService $pinService
    ) {}

    /**
     * Update display and triage settings. PIN must match a supervisor of the active program or an admin.
     */
    public function update(UpdatePublicDisplaySettingsRequest $request): JsonResponse
    {
        $program = Program::query()->where('is_active', true)->first();

        if (! $program) {
            return response()->json(['message' => 'No active program.'], 400);
        }

        $result = $this->pinService->validatePinForActiveProgram($request->validated('pin'));

        if (! $result) {
            return response()->json(['message' => 'Invalid PIN.'], 401);
        }

        $settings = $program->settings ?? [];
        $payload = $request->validated();
        unset($payload['pin']);

        foreach (['display_audio_muted', 'display_audio_volume', 'display_tts_voice', 'enable_display_hid_barcode', 'enable_public_triage_hid_barcode'] as $key) {
            if (array_key_exists($key, $payload)) {
                $settings[$key] = $payload[$key];
            }
        }

        $program->update(['settings' => $settings]);
        $program = $program->fresh();

        event(new DisplaySettingsUpdated(
            $program->getDisplayAudioMuted(),
            $program->getDisplayAudioVolume(),
            $program->getDisplayTtsVoice(),
            $program->getEnableDisplayHidBarcode(),
            $program->getEnablePublicTriageHidBarcode()
        ));

        return response()->json([
            'display_audio_muted' => $program->getDisplayAudioMuted(),
            'display_audio_volume' => $program->getDisplayAudioVolume(),
            'display_tts_voice' => $program->getDisplayTtsVoice(),
            'enable_display_hid_barcode' => $program->getEnableDisplayHidBarcode(),
            'enable_public_triage_hid_barcode' => $program->getEnablePublicTriageHidBarcode(),
        ]);
    }
}
