<?php

namespace App\Http\Controllers\Api;

use App\Events\StationDisplaySettingsUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetStationPriorityFirstRequest;
use App\Http\Requests\UpdateStationDisplaySettingsRequest;
use App\Models\Program;
use App\Models\Station;
use App\Services\StationQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Per 08-API-SPEC-PHASE1 §4: Station queue endpoints. Auth: staff assigned or supervisor/admin.
 */
class StationController extends Controller
{
    public function __construct(
        private StationQueueService $stationQueueService
    ) {}

    /**
     * Get queue for a station. Per spec §4.1.
     * Auth: staff assigned to station, or supervisor/admin.
     */
    public function queue(Station $station): JsonResponse
    {
        Gate::authorize('view', $station);

        $data = $this->stationQueueService->getQueueForStation($station);

        return response()->json($data);
    }

    /**
     * Set station priority_first_override. Supervisor/admin only.
     */
    public function setPriorityFirst(SetStationPriorityFirstRequest $request, Station $station): JsonResponse
    {
        Gate::authorize('view', $station);
        $station->update(['priority_first_override' => $request->validated('priority_first')]);

        return response()->json([
            'station' => [
                'id' => $station->id,
                'priority_first_override' => $station->priority_first_override,
            ],
        ]);
    }

    /**
     * Update station display audio settings (mute/volume for /display/station/{id}).
     * Per plan: controlled from staff /station/*; broadcast to display.station.{id} for real-time.
     */
    public function updateDisplaySettings(UpdateStationDisplaySettingsRequest $request, Station $station): JsonResponse
    {
        Gate::authorize('view', $station);

        $settings = $station->settings ?? [];
        if ($request->has('display_audio_muted')) {
            $settings['display_audio_muted'] = $request->boolean('display_audio_muted');
        }
        if ($request->has('display_audio_volume')) {
            $settings['display_audio_volume'] = (float) max(0, min(1, $request->input('display_audio_volume', 1)));
        }
        $station->update(['settings' => $settings]);

        event(new StationDisplaySettingsUpdated(
            $station->id,
            $station->getDisplayAudioMuted(),
            $station->getDisplayAudioVolume()
        ));

        return response()->json([
            'display_audio_muted' => $station->getDisplayAudioMuted(),
            'display_audio_volume' => $station->getDisplayAudioVolume(),
        ]);
    }

    /**
     * List stations for active program. Per spec §4.2.
     * Auth: any authenticated staff.
     */
    public function index(): JsonResponse
    {
        $program = Program::query()->where('is_active', true)->first();

        if (! $program) {
            return response()->json([
                'message' => 'No active program. Please activate a program first.',
            ], 400);
        }

        $data = $this->stationQueueService->listStationsForActiveProgram();

        return response()->json($data);
    }
}
