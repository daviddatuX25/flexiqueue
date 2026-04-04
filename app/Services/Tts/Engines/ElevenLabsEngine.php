<?php

namespace App\Services\Tts\Engines;

use App\Services\ElevenLabsClient;
use App\Services\Tts\Contracts\TtsEngine;
use App\Services\Tts\DTO\SynthesisRequest;
use App\Services\Tts\DTO\SynthesisResult;
use App\Services\Tts\ElevenLabsCredentials;

/**
 * ElevenLabs-backed synthesis; used when config tts.driver = elevenlabs.
 */
final class ElevenLabsEngine implements TtsEngine
{
    public function getProviderKey(): string
    {
        return 'elevenlabs';
    }

    public function isConfigured(): bool
    {
        return ElevenLabsCredentials::resolveApiKey() !== '';
    }

    public function synthesize(SynthesisRequest $request): ?SynthesisResult
    {
        $apiKey = ElevenLabsCredentials::resolveApiKey();
        if ($apiKey === '') {
            return null;
        }

        $voiceSettings = $request->rate !== 1.0 ? ['stability' => 0.5, 'similarity_boost' => 0.75] : null;
        $client = new ElevenLabsClient($apiKey);
        $bytes = $client->generateSpeech(
            $request->text,
            $request->voiceId,
            ElevenLabsCredentials::resolveModelId(),
            $voiceSettings
        );

        if ($bytes === null || $bytes === '') {
            return null;
        }

        return new SynthesisResult($bytes, [
            'chars' => mb_strlen($request->text),
        ]);
    }

    public function listVoices(): array
    {
        $apiKey = ElevenLabsCredentials::resolveApiKey();
        if ($apiKey !== '') {
            $client = new ElevenLabsClient($apiKey);
            $apiVoices = $client->getVoices();
            if ($apiVoices !== []) {
                return array_map(static function (array $v) {
                    return [
                        'id' => $v['voice_id'] ?? '',
                        'name' => $v['name'] ?? 'Unknown',
                        'lang' => $v['labels']['accent'] ?? ($v['labels']['language'] ?? null),
                    ];
                }, array_filter($apiVoices, fn ($v) => ! empty($v['voice_id'] ?? '')));
            }
        }

        return config('tts.voices', []);
    }

    public function getCacheIdentitySegment(): string
    {
        return 'elevenlabs|'.ElevenLabsCredentials::resolveModelId();
    }

    public function getAssetIdentityModelKey(): string
    {
        return ElevenLabsCredentials::resolveModelId();
    }
}
