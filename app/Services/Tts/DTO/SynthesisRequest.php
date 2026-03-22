<?php

namespace App\Services\Tts\DTO;

use App\Services\Tts\Contracts\TtsEngine;

/**
 * Normalized input for {@see TtsEngine::synthesize()}.
 */
final readonly class SynthesisRequest
{
    /**
     * @param  non-empty-string  $text
     * @param  non-empty-string  $voiceId  Engine-specific voice identifier
     */
    public function __construct(
        public string $text,
        public string $voiceId,
        public float $rate,
    ) {}
}
