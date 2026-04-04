<?php

namespace App\Services\Tts\Engines;

use App\Services\Tts\Contracts\TtsEngine;
use App\Services\Tts\DTO\SynthesisRequest;
use App\Services\Tts\DTO\SynthesisResult;

/**
 * Disabled / no server TTS: display falls back to Web Speech per policy.
 */
final class NullTtsEngine implements TtsEngine
{
    public function getProviderKey(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function synthesize(SynthesisRequest $request): ?SynthesisResult
    {
        return null;
    }

    public function listVoices(): array
    {
        return [];
    }

    public function getCacheIdentitySegment(): string
    {
        return 'null';
    }

    public function getAssetIdentityModelKey(): string
    {
        return '';
    }
}
