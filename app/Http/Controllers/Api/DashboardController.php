<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per 08-API-SPEC-PHASE1 §6.1: Dashboard endpoints. Auth: role:admin,supervisor.
 * Per central-edge Phase A: program_id from query; when missing returns empty stats.
 */
class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService
    ) {}

    /**
     * Get dashboard stats (active program, sessions, stations, staff).
     */
    public function stats(Request $request): JsonResponse
    {
        $programId = $request->query('program_id');
        $programId = is_numeric($programId) ? (int) $programId : null;

        return response()->json($this->dashboardService->getStats($programId, $request->user()));
    }

    /**
     * Get stations for dashboard table (with current_client).
     */
    public function stations(Request $request): JsonResponse
    {
        $programId = $request->query('program_id');
        $programId = is_numeric($programId) ? (int) $programId : null;

        return response()->json($this->dashboardService->getDashboardStations($programId));
    }
}
