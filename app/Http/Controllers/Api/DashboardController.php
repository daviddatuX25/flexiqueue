<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

/**
 * Per 08-API-SPEC-PHASE1 §6.1: Dashboard endpoints. Auth: role:admin,supervisor.
 */
class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService
    ) {}

    /**
     * Get dashboard stats (active program, sessions, stations, staff).
     */
    public function stats(): JsonResponse
    {
        return response()->json($this->dashboardService->getStats());
    }

    /**
     * Get stations for dashboard table (with current_client).
     */
    public function stations(): JsonResponse
    {
        return response()->json($this->dashboardService->getDashboardStations());
    }
}
