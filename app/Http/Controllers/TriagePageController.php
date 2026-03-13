<?php

namespace App\Http\Controllers;

use App\Models\IdentityRegistration;
use App\Models\Program;
use App\Support\ClientIdTypes;
use App\Services\StationQueueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per 09-UI-ROUTES-PHASE1 §3.2: Triage page with activeProgram and tracks for category/track select.
 * Per central-edge A.2.2: program resolved from user.assignedStation.program; 422 when no station.
 * Per follow-up: admin/supervisor with no station use same session program as Station (one selection for both).
 */
class TriagePageController extends Controller
{
    public function __construct(
        private StationQueueService $stationQueueService
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $user->load(['assignedStation.program.serviceTracks']);

        $isAdminOrSupervisorWithoutStation = ($user->isAdmin() || $user->isSupervisorForAnyProgram()) && $user->assignedStation === null;

        // Staff with per-program station assignments in more than one active program can choose
        // which program to work in; shares the same session key as Station page.
        $staffHasMultiProgramAssignments = ! $user->isAdmin()
            && ! $user->isSupervisorForAnyProgram()
            && $user->programStationAssignments()
                ->whereHas('program', fn ($q) => $q->where('is_active', true))
                ->distinct('program_id')
                ->count('program_id') > 1;

        $canSwitchProgram = $isAdminOrSupervisorWithoutStation || $staffHasMultiProgramAssignments;

        // Optional ?program=id: set session and redirect (shared with Station so one selection applies to both).
        if ($canSwitchProgram && $request->has('program')) {
            $programId = (int) $request->query('program');
            $programModel = Program::query()->where('id', $programId)->where('is_active', true)->first();
            if ($programModel) {
                $request->session()->put(StationPageController::SESSION_KEY_PROGRAM_ID, $programModel->id);

                return redirect('/triage');
            }
        }

        // Shared resolver with Station/Overrides:
        // - Session key → assigned station program → first active program.
        $program = StationPageController::resolveProgramForStaffWithoutStation($request);

        if (! $program) {
            $response = redirect()->back()->withErrors(['station' => 'No station assigned.']);
            $response->setStatusCode(422);

            return $response;
        }

        $program->load('serviceTracks:id,program_id,name,color_code,is_default');
        $programSettings = $program->settings();
        $programPayload = [
            'id' => $program->id,
            'name' => $program->name,
            'is_active' => $program->is_active,
            'is_paused' => $program->is_paused,
            // Per XM2O identity-binding plan: expose mode to staff triage UI.
            'identity_binding_mode' => $programSettings->getIdentityBindingMode(),
            'tracks' => $program->serviceTracks->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'color_code' => $t->color_code,
                'is_default' => $t->is_default,
            ])->values()->all(),
        ];

        $footerStats = $this->stationQueueService->getProgramFooterStats($program);
        $displayScanTimeoutSeconds = $program->settings()->getDisplayScanTimeoutSeconds();

        $pendingRegistrations = IdentityRegistration::query()
            ->forProgram($program->id)
            ->pending()
            ->with(['session.token', 'idVerifiedBy'])
            ->orderByDesc('requested_at')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'birth_year' => $r->birth_year,
                'client_category' => $r->client_category,
                'id_type' => $r->id_type,
                'id_number_last4' => $r->id_number_last4,
                'id_verified_at' => $r->id_verified_at?->toIso8601String(),
                'id_verified_by_user_id' => $r->id_verified_by_user_id,
                'id_verified_by' => $r->idVerifiedBy?->name,
                'requested_at' => $r->requested_at?->toIso8601String(),
                'session_id' => $r->session_id,
                'session_alias' => $r->session?->token?->physical_id ?? null,
            ])
            ->values()
            ->all();

        $programsForSelector = [];
        if ($canSwitchProgram) {
            $query = Program::query()
                ->where('is_active', true)
                ->orderBy('name');

            if ($staffHasMultiProgramAssignments) {
                $assignedProgramIds = $user->programStationAssignments()
                    ->whereHas('program', fn ($q) => $q->where('is_active', true))
                    ->pluck('program_id')
                    ->unique()
                    ->all();

                $query->whereIn('id', $assignedProgramIds);
            }

            $programsForSelector = $query
                ->get(['id', 'name'])
                ->map(fn (Program $p) => ['id' => $p->id, 'name' => $p->name])
                ->values()
                ->all();
        }

        return Inertia::render('Triage/Index', [
            'currentProgram' => $programPayload,
            'program' => $programPayload,
            'activeProgram' => $programPayload,
            'canSwitchProgram' => $canSwitchProgram,
            'programs' => $programsForSelector,
            'queueCount' => $footerStats['queue_count'],
            'processedToday' => $footerStats['processed_today'],
            'display_scan_timeout_seconds' => $displayScanTimeoutSeconds,
            'staff_triage_allow_hid_barcode' => $user->staff_triage_allow_hid_barcode ?? true,
            'staff_triage_allow_camera_scanner' => $user->staff_triage_allow_camera_scanner ?? true,
            'id_types' => ClientIdTypes::all(),
            'pending_identity_registrations' => $pendingRegistrations,
        ]);
    }
}
