<?php

namespace App\Repositories;

use App\Models\PrintSetting;

/**
 * Per REFACTORING-ISSUE-LIST.md Issue 8: holds "get or create" singleton logic for PrintSetting.
 */
class PrintSettingRepository
{
    public function getInstance(): PrintSetting
    {
        $settings = PrintSetting::first();
        if (! $settings) {
            $settings = PrintSetting::create([
                'cards_per_page' => 6,
                'paper' => 'a4',
                'orientation' => 'portrait',
                'show_hint' => true,
                'show_cut_lines' => true,
            ]);
        }

        return $settings;
    }
}
