<?php

namespace App\Services\Tts\Contracts;

use App\Services\Tts\DTO\SynthesisRequest;
use App\Services\Tts\DTO\SynthesisResult;

/**
 * Pluggable TTS synthesis backend (ElevenLabs, future Azure/Google, or disabled).
 */
interface TtsEngine
{
    /**
     * Stable provider id for cache keys, logging, and usage ledgers (e.g. elevenlabs, azure).
     */
    public function getProviderKey(): string;

    public function isConfigured(): bool;

    public function synthesize(SynthesisRequest $request): ?SynthesisResult;

    /**
     * Voices for admin UI (same shape as legacy TtsService::getVoicesList).
     *
     * @return array<int, array{id: string, name: string, lang?: string|null}>
     */
    public function listVoices(): array;

    /**
     * Engine-specific identity for file cache keys (provider + model or equivalent).
     * Must change when the same text/voice/rate would produce different audio bytes.
     */
    public function getCacheIdentitySegment(): string;

    /**
     * Model or engine variant for revision asset identity (empty if N/A).
     */
    public function getAssetIdentityModelKey(): string;
}
