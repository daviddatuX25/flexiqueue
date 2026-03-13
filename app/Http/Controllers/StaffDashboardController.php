<?php

namespace App\Http\Controllers;

use App\Services\StaffDashboardService;
use App\Services\StationQueueService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff dashboard: per-staff metrics only. Per docs/plans/STAFF-DASHBOARD-PLAN.md.
 * No quick links; footer nav provides Station / Triage / Program Overrides.
 */
class StaffDashboardController extends Controller
{
    public function __construct(
        private StaffDashboardService $staffDashboardService,
        private StationQueueService $stationQueueService
    ) {}

    public function __invoke(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();
        if ($user->isAdmin()) {
            return redirect()->route('admin.dashboard');
        }
        $metrics = $this->staffDashboardService->getMetricsForUser($user);

        // Per central-edge Phase A: program from staff's assigned station only.
        $program = $user->assignedStation?->program;
        $footerStats = $program
            ? $this->stationQueueService->getProgramFooterStats($program)
            : ['queue_count' => 0, 'processed_today' => 0];

        return Inertia::render('Staff/Dashboard', [
            'metrics' => $metrics,
            'queueCount' => $footerStats['queue_count'],
            'processedToday' => $footerStats['processed_today'],
        ]);
    }
}
