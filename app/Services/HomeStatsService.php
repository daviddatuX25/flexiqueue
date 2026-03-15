<?php

namespace App\Services;

use App\Models\Session;
use App\Models\Site;

/**
 * Per public-site plan: global and site-scoped stats for homepage and site landing.
 */
class HomeStatsService
{
    private const CACHE_KEY_GLOBAL = 'home_stats_global';

    private const CACHE_TTL_SECONDS = 30;

    /**
     * Global stats: total people served and total program hours (all sites, all time).
     */
    public function getGlobalStats(): array
    {
        return cache()->remember(self::CACHE_KEY_GLOBAL, self::CACHE_TTL_SECONDS, function () {
            $servedCount = Session::query()
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->count();

            $sessionHours = Session::query()
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->whereNotNull('started_at')
                ->selectRaw('SUM(TIMESTAMPDIFF(SECOND, started_at, completed_at) / 3600.0) as hours')
                ->value('hours');

            return [
                'served_count' => $servedCount,
                'session_hours' => round((float) ($sessionHours ?? 0), 1),
            ];
        });
    }

    /**
     * Site-scoped stats: served count and session hours for programs belonging to the given site.
     */
    public function getSiteStats(Site $site): array
    {
        $programIds = $site->programs()->pluck('id')->all();
        if (empty($programIds)) {
            return ['served_count' => 0, 'session_hours' => 0.0];
        }

        $servedCount = Session::query()
            ->whereIn('program_id', $programIds)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->count();

        $sessionHours = Session::query()
            ->whereIn('program_id', $programIds)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereNotNull('started_at')
            ->selectRaw('SUM(TIMESTAMPDIFF(SECOND, started_at, completed_at) / 3600.0) as hours')
            ->value('hours');

        return [
            'served_count' => $servedCount,
            'session_hours' => round((float) ($sessionHours ?? 0), 1),
        ];
    }
}
