<?php

namespace App\Services\Tts;

/**
 * TTS budget policy from site settings. Per phase4 governance plan.
 */
final readonly class TtsBudgetPolicy
{
    public function __construct(
        public bool $enabled,
        public string $mode, // 'chars'
        public string $period, // 'daily' | 'monthly'
        public int $limit,
        public int $warningThresholdPct,
        public bool $blockOnLimit,
    ) {}

    public static function fromSiteSettings(?array $settings): self
    {
        $budget = $settings['tts_budget'] ?? [];
        if (! is_array($budget)) {
            $budget = [];
        }

        return new self(
            enabled: (bool) ($budget['enabled'] ?? false),
            mode: 'chars',
            period: in_array($budget['period'] ?? '', ['daily', 'monthly'], true) ? $budget['period'] : 'monthly',
            limit: max(0, (int) ($budget['limit'] ?? 0)),
            warningThresholdPct: max(0, min(100, (int) ($budget['warning_threshold_pct'] ?? 80))),
            blockOnLimit: (bool) ($budget['block_on_limit'] ?? false),
        );
    }

    public function isEnforced(): bool
    {
        return $this->enabled && $this->limit > 0;
    }
}
