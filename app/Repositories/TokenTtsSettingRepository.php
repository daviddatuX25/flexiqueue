<?php

namespace App\Repositories;

use App\Models\TokenTtsSetting;

/**
 * Per REFACTORING-ISSUE-LIST.md Issue 8: holds "get or create" singleton logic for TokenTtsSetting.
 */
class TokenTtsSettingRepository
{
    public function getInstance(): TokenTtsSetting
    {
        $settings = TokenTtsSetting::first();
        if (! $settings) {
            $settings = TokenTtsSetting::create([
                'voice_id' => null,
                'rate' => (float) config('tts.default_rate', 0.84),
            ]);
        }

        return $settings;
    }
}
