<?php

namespace App\Services\Tts;

use App\Models\Site;

/**
 * Guards TTS generation against site budget limits.
 * Per phase4: check before synthesize; block when exceeded and block_on_limit.
 * When platform global budget is enforced, uses weighted per-site limits and the platform pool.
 */
class TtsBudgetGuard
{
    public function __construct(
        private readonly TtsUsageRollupService $rollupService,
        private readonly TtsPlatformBudgetService $platformBudgetService
    ) {}

    public function canGenerate(?int $siteId, int $charsToAdd): bool
    {
        if ($siteId === null) {
            return true;
        }

        $site = Site::find($siteId);
        if ($site === null) {
            return true;
        }

        if ($this->platformBudgetService->isGlobalEnforced()) {
            $settings = $this->platformBudgetService->getSettingsRecord();
            $periodKey = $this->platformBudgetService->periodKeyForPlatform();
            $effective = $this->platformBudgetService->getEffectiveLimitForSite($siteId) ?? 0;

            $currentSite = $this->rollupService->getCharsUsed($siteId, $periodKey);
            $afterSite = $currentSite + $charsToAdd;

            if ($settings->block_on_limit && $afterSite > $effective) {
                return false;
            }

            $totalUsed = $this->platformBudgetService->getTotalCharsUsedForPlatformPeriod();
            if ($settings->block_on_limit && $totalUsed + $charsToAdd > $settings->char_limit) {
                return false;
            }

            return true;
        }

        $policy = TtsBudgetPolicy::fromSiteSettings($site->settings);
        if (! $policy->isEnforced()) {
            return true;
        }

        $periodKey = TtsPeriodKey::forNow($policy->period);
        $current = $this->rollupService->getCharsUsed($siteId, $periodKey);
        $after = $current + $charsToAdd;

        if ($after > $policy->limit && $policy->blockOnLimit) {
            return false;
        }

        return true;
    }

    /**
     * Concurrency scope used to serialize budget-checked generation and reduce race overshoot.
     */
    public function lockScope(?int $siteId): ?string
    {
        if ($siteId === null) {
            return null;
        }

        $site = Site::find($siteId);
        if ($site === null) {
            return null;
        }

        if ($this->platformBudgetService->isGlobalEnforced()) {
            return 'platform:'.$this->platformBudgetService->periodKeyForPlatform();
        }

        $policy = TtsBudgetPolicy::fromSiteSettings($site->settings);
        if (! $policy->isEnforced()) {
            return null;
        }

        return 'site:'.$siteId.':'.TtsPeriodKey::forNow($policy->period);
    }
}
