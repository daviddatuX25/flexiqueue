<?php

namespace App\Services\Tts;

use App\Models\Site;
use App\Models\SiteTtsUsageEvent;

/**
 * Records TTS generation usage for metering and budget rollups.
 * Invoked on every successful synthesize (jobs + preview).
 */
class TtsGenerationMeter
{
    public function __construct(
        private readonly TtsUsageRollupService $rollupService,
        private readonly TtsPlatformBudgetService $platformBudgetService
    ) {}

    public function record(
        ?int $siteId,
        string $provider,
        int $charsUsed,
        string $source
    ): void {
        SiteTtsUsageEvent::create([
            'site_id' => $siteId,
            'provider' => $provider,
            'chars_used' => $charsUsed,
            'source' => $source,
        ]);

        if ($siteId !== null) {
            $site = Site::find($siteId);
            if ($this->platformBudgetService->isGlobalEnforced()) {
                $period = $this->platformBudgetService->getSettingsRecord()->period;
            } else {
                $period = $site ? TtsBudgetPolicy::fromSiteSettings($site->settings)->period : 'monthly';
            }
            $periodKey = TtsPeriodKey::forNow($period === 'daily' ? 'daily' : 'monthly');
            $this->rollupService->increment($siteId, $periodKey, $charsUsed, 1);
        }
    }
}
