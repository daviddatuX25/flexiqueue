<?php

namespace App\Http\Controllers;

use App\Http\Controllers\StationPageController;
use App\Models\PermissionRequest;
use App\Models\Program;
use App\Models\Station;
use App\Support\SiteResolver;
use App\Models\TemporaryAuthorization;
use App\Services\StationQueueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Program Overrides page: Generate PIN/QR, view and manage permission requests.
 * Per TRACK-OVERRIDES-REFACTOR: tracks, authorizations list, target_track.
 * Per follow-up: admin/supervisor with no station use same session program as Station/Triage; pass canSwitchProgram for footer.
 */
class ProgramOverridesPageController extends Controller
{
    public function __construct(
        private StationQueueService $stationQueueService
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $canApprove = $user->isAdmin() || $user->isSupervisorForAnyProgram();
        $isAdminOrSupervisorWithoutStation = ($user->isAdmin() || $user->isSupervisorForAnyProgram()) && $user->assignedStation === null;

        // Staff with per-program station assignments in more than one active program can choose
        // which program overrides context to work in; shares session key with Station/Triage.
        $staffHasMultiProgramAssignments = ! $user->isAdmin()
            && ! $user->isSupervisorForAnyProgram()
            && $user->programStationAssignments()
                ->whereHas('program', fn ($q) => $q->where('is_active', true))
                ->distinct('program_id')
                ->count('program_id') > 1;

        $canSwitchProgram = $isAdminOrSupervisorWithoutStation || $staffHasMultiProgramAssignments;

        // Resolve site for program list and ?program= validation: staff use their site so multi-program selector works when not on default site.
        $siteId = $user->site_id ?? SiteResolver::default()->id;

        // Optional ?program=id: set session and redirect (shared with Station/Triage).
        if ($canSwitchProgram && $request->has('program')) {
            $programId = (int) $request->query('program');
            $programModel = Program::query()
                ->forSite($siteId)
                ->where('id', $programId)
                ->where('is_active', true)
                ->first();
            if ($programModel) {
                $request->session()->put(StationPageController::SESSION_KEY_PROGRAM_ID, $programModel->id);

                return redirect('/track-overrides');
            }
        }

        // Shared resolver with Station/Triage:
        // - Session key → assigned station program → first active program.
        $program = StationPageController::resolveProgramForStaffWithoutStation($request);
        $program = $program && $program->is_active ? $program : null;
        $footerStats = $this->stationQueueService->getProgramFooterStats($program);
        $stations = [];
        $tracks = [];
        if ($program) {
            $stations = $program->stations()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Station $s) => ['id' => $s->id, 'name' => $s->name])
                ->values()
                ->all();
            $tracks = $program->serviceTracks()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])
                ->values()
                ->all();
        }

        $authorizations = [];
        if ($canApprove) {
            $authorizations = TemporaryAuthorization::where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(['id', 'type', 'expiry_mode', 'max_uses', 'used_count', 'created_at', 'expires_at', 'used_at', 'last_used_at'])
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'type' => $a->type,
                    'expiry_mode' => $a->expiry_mode,
                    'max_uses' => $a->max_uses,
                    'used_count' => $a->used_count,
                    'created_at' => $a->created_at?->toIso8601String(),
                    'expires_at' => $a->expires_at?->toIso8601String(),
                    'used_at' => $a->used_at?->toIso8601String(),
                    'last_used_at' => $a->last_used_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        $query = PermissionRequest::query()
            ->with(['session.serviceTrack', 'session.currentStation', 'requester', 'targetStation', 'targetTrack'])
            ->where('status', 'pending')
            ->orderByDesc('created_at');

        if (! $canApprove) {
            $query->where('requester_user_id', $user->id);
        }

        $pendingRequests = $query->limit(50)->get()->map(fn (PermissionRequest $pr) => [
            'id' => $pr->id,
            'session_id' => $pr->session_id,
            'action_type' => $pr->action_type,
            'reason' => $pr->reason,
            'created_at' => $pr->created_at->toIso8601String(),
            'session' => [
                'id' => $pr->session->id,
                'alias' => $pr->session->alias,
                'status' => $pr->session->status,
                'track' => $pr->session->serviceTrack?->name ?? '—',
                'current_station' => $pr->session->currentStation ? ['id' => $pr->session->currentStation->id, 'name' => $pr->session->currentStation->name] : null,
            ],
            'requester' => ['id' => $pr->requester->id, 'name' => $pr->requester->name],
            'target_station' => $pr->targetStation ? ['id' => $pr->targetStation->id, 'name' => $pr->targetStation->name] : null,
            'target_track' => $pr->targetTrack ? ['id' => $pr->targetTrack->id, 'name' => $pr->targetTrack->name] : null,
        ])->values()->all();

        $programsForSelector = [];
        if ($canSwitchProgram) {
            $query = Program::query()
                ->forSite($siteId)
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

        $currentProgramPayload = $program ? [
            'id' => $program->id,
            'name' => $program->name,
            'is_active' => $program->is_active,
            'is_paused' => $program->is_paused ?? false,
        ] : null;

        return Inertia::render('ProgramOverrides/Index', [
            'canApprove' => $canApprove,
            'canSwitchProgram' => $canSwitchProgram,
            'programs' => $programsForSelector,
            'activeProgram' => $currentProgramPayload,
            'currentProgram' => $currentProgramPayload,
            'stations' => $stations,
            'tracks' => $tracks,
            'authorizations' => $authorizations,
            'pendingRequests' => $pendingRequests,
            'queueCount' => $footerStats['queue_count'],
            'processedToday' => $footerStats['processed_today'],
        ]);
    }
}

