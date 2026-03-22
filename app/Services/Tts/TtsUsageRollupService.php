<?php

namespace App\Services\Tts;

use App\Models\SiteTtsUsageRollup;

/**
 * Maintains and queries site TTS usage rollups for budget checks.
 */
class TtsUsageRollupService
{
    public function increment(int $siteId, string $periodKey, int $charsUsed, int $count = 1): void
    {
        $rollup = SiteTtsUsageRollup::firstOrCreate(
            ['site_id' => $siteId, 'period_key' => $periodKey],
            ['chars_used' => 0, 'generation_count' => 0]
        );
        $rollup->chars_used += $charsUsed;
        $rollup->generation_count += $count;
        $rollup->save();
    }

    /**
     * @return int chars_used for the period
     */
    public function getCharsUsed(int $siteId, string $periodKey): int
    {
        $rollup = SiteTtsUsageRollup::where('site_id', $siteId)->where('period_key', $periodKey)->first();

        return $rollup?->chars_used ?? 0;
    }

    public function sumCharsUsedForPeriodKey(string $periodKey): int
    {
        return (int) SiteTtsUsageRollup::query()
            ->where('period_key', $periodKey)
            ->sum('chars_used');
    }
}
