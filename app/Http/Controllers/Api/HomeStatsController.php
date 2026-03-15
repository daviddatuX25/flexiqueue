<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HomeStatsService;
use Illuminate\Http\JsonResponse;

/**
 * Per public-site plan: public homepage global stats. No auth, throttled.
 */
class HomeStatsController extends Controller
{
    public function __construct(
        private HomeStatsService $homeStatsService
    ) {}

    /**
     * GET /api/home-stats — global served count and session hours.
     */
    public function index(): JsonResponse
    {
        $stats = $this->homeStatsService->getGlobalStats();

        return response()->json($stats);
    }
}
