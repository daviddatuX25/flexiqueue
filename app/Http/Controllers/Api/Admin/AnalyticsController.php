<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Analytics API for Admin Analytics page. Auth: role:admin.
 */
class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AnalyticsService $analytics
    ) {}

    /**
     * Query params: program_id (optional), track_id (optional), from (Y-m-d), to (Y-m-d).
     */
    public function summary(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->json($this->analytics->getSummary($filters));
    }

    /**
     * Throughput over time (per day or per hour when single day). Same query params.
     */
    public function throughput(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->json($this->analytics->getThroughput($filters));
    }

    /**
     * Wait time distribution buckets. Same query params.
     */
    public function waitTimeDistribution(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->json($this->analytics->getWaitTimeDistribution($filters));
    }

    /**
     * Station utilization (busy vs idle). program_id, from, to.
     */
    public function stationUtilization(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->json($this->analytics->getStationUtilization($filters));
    }

    /**
     * Track performance comparison. Same query params.
     */
    public function tracks(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->json($this->analytics->getTrackPerformance($filters));
    }

    /**
     * Busiest hours heatmap. Same query params.
     */
    public function busiestHours(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->json($this->analytics->getBusiestHours($filters));
    }

    /**
     * Drop-off funnel. Same query params.
     */
    public function dropOffFunnel(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->json($this->analytics->getDropOffFunnel($filters));
    }

    /**
     * Token and TTS health (no date/program filters). Per site-scoping-migration-spec §2: scoped by user's site.
     */
    public function tokenTtsHealth(Request $request): JsonResponse
    {
        $siteId = $request->user()->isSuperAdmin() ? null : $request->user()->site_id;

        return response()->json($this->analytics->getTokenTtsHealth($siteId));
    }

    /**
     * @return array{program_id?: int, track_id?: int, from?: string, to?: string}
     */
    private function filters(Request $request): array
    {
        $filters = [];
        if ($request->filled('program_id')) {
            $filters['program_id'] = (int) $request->input('program_id');
        }
        if ($request->filled('track_id')) {
            $filters['track_id'] = (int) $request->input('track_id');
        }
        if ($request->filled('from')) {
            $filters['from'] = $request->input('from');
        }
        if ($request->filled('to')) {
            $filters['to'] = $request->input('to');
        }

        return $filters;
    }
}
