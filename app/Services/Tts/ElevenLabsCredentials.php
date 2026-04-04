<?php

namespace App\Services\Tts;

use App\Models\TtsAccount;

/**
 * Resolves ElevenLabs API key and model from DB account (when provider matches driver) or config.
 */
final class ElevenLabsCredentials
{
    /**
     * Active account must match {@see config('tts.driver')} so credentials are not mixed across engines.
     */
    public static function resolveApiKey(): string
    {
        $active = TtsAccount::getActiveMatchingDriver();
        if ($active !== null) {
            $key = $active->getApiKey();
            if ($key !== '') {
                return $key;
            }
        }

        return (string) config('tts.elevenlabs.api_key', '');
    }

    public static function resolveModelId(): string
    {
        $active = TtsAccount::getActiveMatchingDriver();
        if ($active !== null && $active->model_id !== '') {
            return $active->model_id;
        }

        return (string) config('tts.elevenlabs.model_id', 'eleven_multilingual_v2');
    }
}
