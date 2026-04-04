<?php

namespace App\Services\Tts;

/**
 * Distributes an integer character pool across sites by weights.
 * Remainder units are assigned to sites in ascending site_id order until exhausted.
 */
final class TtsWeightedBudgetAllocator
{
    /**
     * @param  array<int, positive-int>  $weightsBySiteId
     * @return array<int, int> site_id => effective char limit
     */
    public static function allocate(int $pool, array $siteIds, array $weightsBySiteId): array
    {
        if ($pool <= 0) {
            return array_fill_keys($siteIds, 0);
        }

        $siteIds = array_values(array_unique(array_map('intval', $siteIds)));
        sort($siteIds);

        if ($siteIds === []) {
            return [];
        }

        $totalWeight = 0;
        foreach ($siteIds as $sid) {
            $w = (int) ($weightsBySiteId[$sid] ?? 1);
            if ($w < 1) {
                $w = 1;
            }
            $totalWeight += $w;
        }

        if ($totalWeight <= 0) {
            $per = intdiv($pool, count($siteIds));
            $rem = $pool % count($siteIds);
            $out = [];
            foreach ($siteIds as $i => $sid) {
                $out[$sid] = $per + ($i < $rem ? 1 : 0);
            }

            return $out;
        }

        $out = [];
        $allocated = 0;
        foreach ($siteIds as $sid) {
            $w = (int) ($weightsBySiteId[$sid] ?? 1);
            if ($w < 1) {
                $w = 1;
            }
            $share = intdiv($pool * $w, $totalWeight);
            $out[$sid] = $share;
            $allocated += $share;
        }

        $remainder = $pool - $allocated;
        foreach ($siteIds as $sid) {
            if ($remainder <= 0) {
                break;
            }
            $out[$sid]++;
            $remainder--;
        }

        return $out;
    }
}
