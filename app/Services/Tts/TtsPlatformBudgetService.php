<?php

namespace App\Services\Tts;

use App\Models\Site;
use App\Models\TtsPlatformBudget;
use App\Models\TtsSiteBudgetWeight;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide TTS budget (weighted) when {@see TtsPlatformBudget::global_enabled} is true.
 */
class TtsPlatformBudgetService
{
    public function __construct(
        private readonly TtsUsageRollupService $rollupService
    ) {}

    public function getSettingsRecord(): TtsPlatformBudget
    {
        return TtsPlatformBudget::settings();
    }

    /**
     * @return array<int, positive-int> site_id => weight
     */
    public function getWeightsBySiteId(): array
    {
        $rows = TtsSiteBudgetWeight::query()->orderBy('site_id')->get();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->site_id] = max(1, (int) $row->weight);
        }

        return $map;
    }

    /**
     * Effective char limit per site for the current platform pool (global mode).
     *
     * @return array<int, int> site_id => effective limit
     */
    public function getEffectiveLimitsBySiteId(): array
    {
        $settings = $this->getSettingsRecord();
        if (! $settings->global_enabled || $settings->char_limit <= 0) {
            return [];
        }

        $siteIds = Site::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $weights = $this->getWeightsBySiteId();

        return TtsWeightedBudgetAllocator::allocate(
            $settings->char_limit,
            $siteIds,
            $weights
        );
    }

    public function getEffectiveLimitForSite(int $siteId): ?int
    {
        $settings = $this->getSettingsRecord();
        if (! $settings->global_enabled || $settings->char_limit <= 0) {
            return null;
        }

        $limits = $this->getEffectiveLimitsBySiteId();

        return $limits[$siteId] ?? 0;
    }

    public function isGlobalEnforced(): bool
    {
        $s = $this->getSettingsRecord();

        return $s->global_enabled && $s->char_limit > 0;
    }

    public function periodKeyForPlatform(): string
    {
        $s = $this->getSettingsRecord();

        return TtsPeriodKey::forNow($s->period === 'daily' ? 'daily' : 'monthly');
    }

    /**
     * Sum of chars_used across all sites for the platform period key (global mode).
     */
    public function getTotalCharsUsedForPlatformPeriod(): int
    {
        $key = $this->periodKeyForPlatform();

        return $this->rollupService->sumCharsUsedForPeriodKey($key);
    }

    /**
     * Dashboard payload for super admin UI.
     */
    public function buildDashboardPayload(): array
    {
        $settings = $this->getSettingsRecord();
        $sites = Site::query()->orderBy('name')->get();
        $weights = $this->getWeightsBySiteId();
        $effective = $this->getEffectiveLimitsBySiteId();
        $periodKey = $this->isGlobalEnforced()
            ? $this->periodKeyForPlatform()
            : null;

        $sitesPayload = [];
        $usagePeriodKeyFn = function (Site $site) use ($settings): string {
            if ($settings->global_enabled && $settings->char_limit > 0) {
                return TtsPeriodKey::forNow($settings->period === 'daily' ? 'daily' : 'monthly');
            }
            $policy = $site->getTtsBudgetPolicy();

            return TtsPeriodKey::forNow($policy->period);
        };

        foreach ($sites as $site) {
            $sid = (int) $site->id;
            $policy = $site->getTtsBudgetPolicy();
            $sitePeriodKey = $usagePeriodKeyFn($site);
            $charsUsed = $this->rollupService->getCharsUsed($sid, $sitePeriodKey);

            $w = $weights[$sid] ?? 1;
            $eff = $effective[$sid] ?? null;

            $sitesPayload[] = [
                'site_id' => $sid,
                'site_name' => $site->name,
                'slug' => $site->slug,
                'weight' => $w,
                'effective_limit' => $eff,
                'chars_used' => $charsUsed,
                'period_key' => $sitePeriodKey,
                'policy' => [
                    'enabled' => $policy->enabled,
                    'limit' => $policy->limit,
                    'period' => $policy->period,
                    'block_on_limit' => $policy->blockOnLimit,
                ],
            ];
        }

        $totalMetered = 0;
        foreach ($sitesPayload as $row) {
            $totalMetered += $row['chars_used'];
        }

        return [
            'global' => [
                'enabled' => (bool) $settings->global_enabled,
                'period' => $settings->period,
                'mode' => 'chars',
                'char_limit' => (int) $settings->char_limit,
                'block_on_limit' => (bool) $settings->block_on_limit,
                'warning_threshold_pct' => (int) $settings->warning_threshold_pct,
            ],
            'global_enforced' => $this->isGlobalEnforced(),
            'period_key' => $periodKey,
            'total_chars_used_platform_period' => $this->isGlobalEnforced()
                ? $this->getTotalCharsUsedForPlatformPeriod()
                : null,
            'sites' => $sitesPayload,
            'total_metered_chars_all_sites' => $totalMetered,
        ];
    }

    /**
     * @param  array<string, mixed>  $global
     * @param  array<int, int>  $weightsBySiteId
     */
    public function updateSettings(array $global, array $weightsBySiteId): TtsPlatformBudget
    {
        return DB::transaction(function () use ($global, $weightsBySiteId) {
            $row = TtsPlatformBudget::settings();
            $row->fill([
                'global_enabled' => (bool) ($global['global_enabled'] ?? $row->global_enabled),
                'period' => in_array($global['period'] ?? '', ['daily', 'monthly'], true)
                    ? $global['period']
                    : $row->period,
                'mode' => 'chars',
                'char_limit' => max(0, (int) ($global['char_limit'] ?? $row->char_limit)),
                'block_on_limit' => (bool) ($global['block_on_limit'] ?? $row->block_on_limit),
                'warning_threshold_pct' => max(0, min(100, (int) ($global['warning_threshold_pct'] ?? $row->warning_threshold_pct))),
            ]);
            $row->save();

            foreach ($weightsBySiteId as $siteId => $weight) {
                $siteId = (int) $siteId;
                if ($siteId <= 0) {
                    continue;
                }
                $w = max(1, (int) $weight);
                TtsSiteBudgetWeight::query()->updateOrCreate(
                    ['site_id' => $siteId],
                    ['weight' => $w]
                );
            }

            return $row->fresh();
        });
    }
}
