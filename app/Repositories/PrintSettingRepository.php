<?php

namespace App\Repositories;

use App\Models\PrintSetting;
use Illuminate\Support\Facades\Log;

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
     * Platform default print template: exactly one logical row with site_id null (super_admin edits via PrintPlatformDefaultsController).
     */
    public function getPlatformTemplate(): PrintSetting
    {
        $rows = PrintSetting::query()->whereNull('site_id')->orderBy('id')->get();
        if ($rows->count() > 1) {
            Log::warning('Multiple print_settings rows with site_id null; using oldest id.', [
                'ids' => $rows->pluck('id')->all(),
            ]);
        }

        $first = $rows->first();
        if ($first !== null) {
            return $first;
        }

        return PrintSetting::create(array_merge(self::DEFAULTS, ['site_id' => null]));
    }

    /**
     * Copy platform template into a new site-scoped row (used when creating a site).
     */
    public function copyPlatformTemplateToSite(int $siteId): PrintSetting
    {
        $existing = PrintSetting::query()->where('site_id', $siteId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $template = $this->getPlatformTemplate();

        return PrintSetting::create([
            'site_id' => $siteId,
            'cards_per_page' => $template->cards_per_page,
            'paper' => $template->paper,
            'orientation' => $template->orientation,
            'show_hint' => $template->show_hint,
            'show_cut_lines' => $template->show_cut_lines,
            'logo_url' => $template->logo_url,
            'footer_text' => $template->footer_text,
            'bg_image_url' => $template->bg_image_url,
        ]);
    }

    /**
     * Get or create print settings for the given site.
     * When $siteId is null, returns the platform template row (site_id null).
     */
    public function getInstance(?int $siteId = null): PrintSetting
    {
        if ($siteId !== null) {
            return PrintSetting::firstOrCreate(
                ['site_id' => $siteId],
                array_merge(self::DEFAULTS, ['site_id' => $siteId])
            );
        }

        return $this->getPlatformTemplate();
    }
}
