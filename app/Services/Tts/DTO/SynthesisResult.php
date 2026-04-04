<?php

namespace App\Services\Tts\DTO;

use App\Services\Tts\Contracts\TtsEngine;

/**
 * Output from {@see TtsEngine::synthesize()}. Optional usage array supports governance metering (e.g. char counts).
 */
final readonly class SynthesisResult
{
    /**
     * @param  non-empty-string  $audioBytes
     * @param  array<string, int|float|string>|null  $usage
     */
    public function __construct(
        public string $audioBytes,
        public ?array $usage = null,
    ) {}
}
