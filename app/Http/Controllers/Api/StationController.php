<?php

namespace App\Http\Controllers\Api;

use App\Events\StationDisplaySettingsUpdated;
use App\Http\Controllers\Controller;
use App\Http\Controllers\StationPageController;
use App\Http\Requests\SetStationPriorityFirstRequest;
use App\Http\Requests\UpdateStationDisplaySettingsRequest;
use App\Models\Program;
use App\Models\Station;
use App\Models\Token;
use App\Services\StationQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            $station->getDisplayAudioVolume(),
        ));

        return response()->json([
            'display_audio_muted' => $station->getDisplayAudioMuted(),
            'display_audio_volume' => $station->getDisplayAudioVolume(),
        ]);
    }

    /**
     * Resolve token by qr_hash to session for station scan. Returns session summary and at_this_station.
     * Per plan: GET /api/stations/{station}/session-by-token?qr_hash=...
     */
    public function sessionByToken(Request $request, Station $station): JsonResponse
    {
        Gate::authorize('view', $station);

        $request->validate(['qr_hash' => ['required', 'string']]);
        $qrHash = $request->query('qr_hash');

        $token = Token::where('qr_code_hash', $qrHash)->first();
        if (! $token) {
            return response()->json([
                'message' => 'Token not found.',
                'error_code' => 'token_not_found',
            ], 404);
        }
        if ($token->status !== 'in_use' || ! $token->currentSession) {
            return response()->json([
                'message' => 'Must be registered on triage first.',
                'error_code' => 'not_registered',
            ], 404);
        }

        $session = $token->currentSession;
        $session->load([
            'serviceTrack.trackSteps.process',
            'serviceTrack.trackSteps.station',
            'currentStation',
            'token',
            'identityRegistration',
        ]);
        $track = $session->serviceTrack;
        $totalSteps = $track ? $track->trackSteps()->count() : 1;
        $currentOrder = (int) ($session->current_step_order ?? 1);

        $unverified = $session->identity_registration_id
            && $session->relationLoaded('identityRegistration')
            && $session->identityRegistration
            && $session->identityRegistration->status === 'pending';

        return response()->json([
            'session_id' => $session->id,
            'alias' => $session->alias,
            'track' => $track?->name ?? '—',
            'status' => $session->status,
            'current_station_id' => $session->current_station_id,
            'current_station' => $session->currentStation?->name ?? '—',
            'client_category' => $session->client_category ?? 'Regular',
            'current_step_order' => $currentOrder,
            'total_steps' => $totalSteps,
            'at_this_station' => $session->current_station_id === $station->id,
            'unverified' => $unverified,
        ]);
    }

    /**
     * List stations for staff's program. Per spec §4.2.
     * Per central-edge Phase A: program from user's assigned station.
     * Auth: any authenticated staff.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $programId = $user->assignedStation?->program_id;

        // Per central-edge follow-up: admin/supervisor with no assigned station uses session-selected program context.
        if ($programId === null) {
            if (! $user->isAdmin() && ! $user->isSupervisorForAnyProgram()) {
                return response()->json([
                    'message' => 'No station assigned.',
                ], 422);
            }

            $programId = $request->session()->get(StationPageController::SESSION_KEY_PROGRAM_ID);
            $program = $programId ? Program::query()->where('id', (int) $programId)->where('is_active', true)->first() : null;
            if (! $program) {
                return response()->json(['message' => 'Program not selected or inactive.'], 422);
            }
            if (! $user->isAdmin() && ! $user->isSupervisorForProgram($program->id)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $programId = $program->id;
        }

        $data = $this->stationQueueService->listStationsForProgram($programId);

        return response()->json($data);
    }
}
