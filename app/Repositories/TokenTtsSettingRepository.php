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
                'default_languages' => [
                    'en' => [
                        // Defaults so segment 1 doesn't become empty in admin preview.
                        'pre_phrase' => 'Calling',
                        'token_bridge_tail' => 'please proceed to your station',
                        'segment1_no_pre_tail_fallback' => 'Calling {token}, please proceed to your station',
                    ],
                    'fil' => [
                        // Defaults so segment 1 doesn't become empty in admin preview.
                        'pre_phrase' => 'Calling',
                        'token_bridge_tail' => 'please proceed to your station',
                        'segment1_no_pre_tail_fallback' => 'Calling {token}, please proceed to your station',
                    ],
                    'ilo' => [
                        // Defaults so segment 1 doesn't become empty in admin preview.
                        'pre_phrase' => 'Calling',
                        'token_bridge_tail' => 'please proceed to your station',
                        'segment1_no_pre_tail_fallback' => 'Calling {token}, please proceed to your station',
                    ],
                ],
                'playback' => [
                    'prefer_generated_audio' => true,
                    'allow_custom_pronunciation' => true,
                    'segment_2_enabled' => true,
                ],
            ]);
        }

        return $settings;
    }
}
