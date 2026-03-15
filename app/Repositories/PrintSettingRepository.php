<?php

namespace App\Repositories;

use App\Models\PrintSetting;

/**
 * Per REFACTORING-ISSUE-LIST.md Issue 8: holds "get or create" logic for PrintSetting.
 * Per site-scoping-migration-spec §4: getInstance(?int $siteId) — one row per site; 403 when site_id null (enforced in controller).
 */
class PrintSettingRepository
{
    private const DEFAULTS = [
        'cards_per_page' => 6,
        'paper' => 'a4',
        'orientation' => 'portrait',
        'show_hint' => true,
        'show_cut_lines' => true,
    ];

    /**
     * Get or create print settings for the given site.
     * When $siteId is null (e.g. TokenPrintController for user with no site), returns first existing row for backward compat.
     */
    public function getInstance(?int $siteId = null): PrintSetting
    {
        if ($siteId !== null) {
            return PrintSetting::firstOrCreate(
                ['site_id' => $siteId],
                array_merge(self::DEFAULTS, ['site_id' => $siteId])
            );
        }

        $settings = PrintSetting::first();
        if (! $settings) {
            $settings = PrintSetting::create(self::DEFAULTS);
        }

        return $settings;
    }
}
