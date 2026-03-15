<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\HomeStatsService;
use Illuminate\Http\JsonResponse;

/**
 * Per public-site plan: site-scoped stats for landing page. No auth, throttled.
 */
class PublicSiteStatsController extends Controller
{
    public function __construct(
        private HomeStatsService $homeStatsService
    ) {}

    /**
     * GET /api/public/site-stats/{site:slug} — served count and session hours for this site.
     */
    public function show(Site $site): JsonResponse
    {
        $stats = $this->homeStatsService->getSiteStats($site);

        return response()->json($stats);
    }
}
