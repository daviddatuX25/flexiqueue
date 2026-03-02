<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\Station;
use App\Services\StationQueueService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per 09-UI-ROUTES-PHASE1 §3.3: Station page. Staff see assigned station; supervisor/admin can switch.
 */
class StationPageController extends Controller
{
    public function __construct(
        private StationQueueService $stationQueueService
    ) {}

    /**
     * Show station UI. Resolve station: route param > user's assigned (must be in active program) > first available.
     */
    public function __invoke(Request $request, ?Station $station = null): Response
    {
        $user = $request->user();
        $program = Program::where('is_active', true)->first();
        $footerStats = $this->stationQueueService->getProgramFooterStats($program);

        $resolvedStation = $station;
        if (! $resolvedStation && $program) {
            $assigned = $user->assignedStationForProgram($program->id);
            if ($assigned) {
                $resolvedStation = $assigned;
            }
        }

        $stationsList = [];
        $tracksList = [];
        $displayScanTimeoutSeconds = 20;
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
            $displayScanTimeoutSeconds = $program->getDisplayScanTimeoutSeconds();
        }

        return Inertia::render('Station/Index', [
            'station' => $resolvedStation ? [
                'id' => $resolvedStation->id,
                'name' => $resolvedStation->name,
            ] : null,
            'stations' => $stationsList,
            'tracks' => $tracksList,
            'canSwitchStation' => $user->isAdmin() || $user->isSupervisorForAnyProgram(),
            'queueCount' => $footerStats['queue_count'],
            'processedToday' => $footerStats['processed_today'],
            'display_scan_timeout_seconds' => $displayScanTimeoutSeconds,
        ]);
    }
}
