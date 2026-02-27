<?php

namespace App\Http\Controllers;

use App\Services\StaffDashboardService;
use App\Services\StationQueueService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff dashboard: per-staff metrics only. Per docs/plans/STAFF-DASHBOARD-PLAN.md.
 * No quick links; footer nav provides Station / Triage / Track Overrides.
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

        $program = \App\Models\Program::where('is_active', true)->first();
        $footerStats = $this->stationQueueService->getProgramFooterStats($program);

        return Inertia::render('Staff/Dashboard', [
            'metrics' => $metrics,
            'queueCount' => $footerStats['queue_count'],
            'processedToday' => $footerStats['processed_today'],
        ]);
    }
}
