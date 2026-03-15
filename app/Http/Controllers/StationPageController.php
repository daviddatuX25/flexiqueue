<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\Station;
use App\Services\StaffAssignmentService;
use App\Support\SiteResolver;
use App\Services\StationQueueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per 09-UI-ROUTES-PHASE1 §3.3: Station page. Staff see assigned station; supervisor/admin can switch.
 * Per central-edge-v2-final Phase A: program resolved from station.program_id when station present (A.2.1).
 * Per follow-up: admin/supervisor with no station use session staff_selected_program_id or ?program= (one selection for station + triage).
 */
class StationPageController extends Controller
{
    public const SESSION_KEY_PROGRAM_ID = 'staff_selected_program_id';

    public const SESSION_KEY_STATION_ID = 'staff_selected_station_id';

    public function __construct(
        private StaffAssignmentService $staffAssignmentService,
        private StationQueueService $stationQueueService
    ) {}

    /**
     * Show station UI. Resolve station: route param > user's assigned (must be in active program) > first available.
     */
    public function __invoke(Request $request, ?Station $station = null): Response|RedirectResponse
    {
        $user = $request->user();
        $isAdminOrSupervisorWithoutStation = ($user->isAdmin() || $user->isSupervisorForAnyProgram()) && $user->assignedStation === null;

        // Staff with per-program station assignments in more than one active program can choose
        // which program to work in (shared selector across Station/Triage/Overrides).
        $staffHasMultiProgramAssignments = ! $user->isAdmin()
            && ! $user->isSupervisorForAnyProgram()
            && $user->programStationAssignments()
                ->whereHas('program', fn ($q) => $q->where('is_active', true))
                ->distinct('program_id')
                ->count('program_id') > 1;

        $canSwitchProgram = $isAdminOrSupervisorWithoutStation || $staffHasMultiProgramAssignments;

        // Resolve site for program list and ?program= validation: staff use their site so multi-program selector works when not on default site.
        $siteId = $user->site_id ?? SiteResolver::default()->id;

        // Optional ?program=id: set session and redirect so one selection applies to station and triage.
        // Always redirect to base /station (no station id) so the new program context is clear; otherwise
        // we could land on a station from the previous program (e.g. /station/5?program=2 → /station).
        if ($canSwitchProgram && $request->has('program')) {
            $programId = (int) $request->query('program');
            $programModel = Program::query()
                ->forSite($siteId)
                ->where('id', $programId)
                ->where('is_active', true)
                ->first();
            if ($programModel) {
                $request->session()->put(self::SESSION_KEY_PROGRAM_ID, $programModel->id);

                return redirect('/station');
            }
        }

        // A.2.1 + selector semantics:
        // - If a station is in the URL, its program wins.
        // - Otherwise, prefer the shared session selection when available.
        // - Otherwise, fall back to the user's assigned station program or first active.
        if ($station?->program) {
            $program = $station->program;
        } else {
            $program = self::resolveProgramForStaffWithoutStation($request);
        }
        $footerStats = $this->stationQueueService->getProgramFooterStats($program);

        $resolvedStation = $station;
        if (! $resolvedStation && $program) {
            $assigned = $this->staffAssignmentService->getStationForUser($user, $program->id);
            if ($assigned) {
                $resolvedStation = $assigned;
            }
        }

        // Remember last station: when visiting /station with no station, redirect to last selected station if valid.
        $canSwitchStation = $user->isAdmin() || $user->isSupervisorForAnyProgram();
        if (! $resolvedStation && $program && $canSwitchStation) {
            $sessionStationId = $request->session()->get(self::SESSION_KEY_STATION_ID);
            if ($sessionStationId) {
                $lastStation = Station::query()
                    ->where('id', (int) $sessionStationId)
                    ->where('program_id', $program->id)
                    ->where('is_active', true)
                    ->first();
                if ($lastStation) {
                    return redirect()->route('station', ['station' => $lastStation->id]);
                }
            }
        }

        // Persist selected station so next visit to /station redirects here.
        if ($resolvedStation && $canSwitchStation) {
            $request->session()->put(self::SESSION_KEY_STATION_ID, $resolvedStation->id);
        }

        $stationsList = [];
        $tracksList = [];
        $displayScanTimeoutSeconds = 20;
        $currentProgramPayload = null;
        if ($program) {
            $stationsList = $program->stations()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Station $s) => ['id' => $s->id, 'name' => $s->name])
                ->values()
                ->all();
            $tracksList = $program->serviceTracks()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])
                ->values()
                ->all();
            $displayScanTimeoutSeconds = $program->settings()->getDisplayScanTimeoutSeconds();
            $currentProgramPayload = [
                'id' => $program->id,
                'name' => $program->name,
                'is_active' => $program->is_active,
                'is_paused' => $program->is_paused ?? false,
            ];
        }

        $programsForSelector = [];
        if ($canSwitchProgram) {
            $query = Program::query()
                ->forSite($siteId)
                ->where('is_active', true)
                ->orderBy('name');

            // For staff with multiple assignments, restrict selector to programs they can work in.
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

        return Inertia::render('Station/Index', [
            'station' => $resolvedStation ? [
                'id' => $resolvedStation->id,
                'name' => $resolvedStation->name,
            ] : null,
            'currentProgram' => $currentProgramPayload,
            'activeProgram' => $currentProgramPayload,
            'stations' => $stationsList,
            'tracks' => $tracksList,
            'canSwitchStation' => $canSwitchStation,
            'canSwitchProgram' => $canSwitchProgram,
            'programs' => $programsForSelector,
            'queueCount' => $footerStats['queue_count'],
            'processedToday' => $footerStats['processed_today'],
            'display_scan_timeout_seconds' => $displayScanTimeoutSeconds,
        ]);
    }

    /**
     * Resolve the current program for Station:
     * - If session key is set and active, use that (shared with Triage/Overrides).
     * - Else, if user has an assigned station in an active program, use that.
     * - Else, fall back to the first active program.
     */
    public static function resolveProgramForStaffWithoutStation(Request $request): ?Program
    {
        $user = $request->user();

        // Use staff's site so session program and fallbacks resolve to programs they can access.
        $siteId = $user?->site_id ?? SiteResolver::default()->id;

        $sessionId = $request->session()->get(self::SESSION_KEY_PROGRAM_ID);
        if ($sessionId) {
            $program = Program::query()
                ->forSite($siteId)
                ->where('id', (int) $sessionId)
                ->where('is_active', true)
                ->first();
            if ($program) {
                return $program;
            }
        }

        $assignedProgram = $user?->assignedStation?->program;
        if ($assignedProgram && $assignedProgram->is_active) {
            return $assignedProgram;
        }

        return Program::query()
            ->forSite($siteId)
            ->where('is_active', true)
            ->orderBy('name')
            ->first();
    }
}
