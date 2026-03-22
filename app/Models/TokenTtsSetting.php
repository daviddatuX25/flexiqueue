<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TokenTtsSetting extends Model
{
    protected $fillable = [
        'voice_id',
        'rate',
        'default_languages',
        'playback',
    ];

    protected $casts = [
        'rate' => 'float',
        'default_languages' => 'array',
        'playback' => 'array',
    ];

    /**
     * Resolve effective voice ID: row value or config default.
     */
    public function getEffectiveVoiceId(): ?string
    {
        $voice = $this->voice_id;
        if ($voice === null || $voice === '') {
            $voice = (string) config('tts.default_voice_id', '');
        }

        return $voice !== '' ? $voice : null;
    }

    /**
     * Resolve effective rate: row value or config default, clamped to sensible range.
     */
    public function getEffectiveRate(): float
    {
        $rate = (float) ($this->rate ?? config('tts.default_rate', 0.84));

        return max(0.5, min(2.0, $rate));
    }

    /**
     * Get default per-language TTS config (en, fil, ilo). Used when a token has no override.
     * Returns ['en' => [...], 'fil' => [...], 'ilo' => [...]] with voice_id, rate, pre_phrase per lang.
     */
    public function getDefaultLanguages(): array
    {
        $raw = $this->default_languages;
        if (! is_array($raw)) {
            return [
                'en' => [],
                'fil' => [],
                'ilo' => [],
            ];
        }

        return [
            'en' => $raw['en'] ?? [],
            'fil' => $raw['fil'] ?? [],
            'ilo' => $raw['ilo'] ?? [],
        ];
    }

    /**
     * Product toggles for display/admin (DB source of truth; defaults match legacy behavior).
     *
     * @return array{prefer_generated_audio: bool, allow_custom_pronunciation: bool, segment_2_enabled: bool}
     */
    public function getPlayback(): array
    {
        $defaults = [
            'prefer_generated_audio' => true,
            'allow_custom_pronunciation' => true,
            'segment_2_enabled' => true,
        ];
        $raw = $this->playback;
        if (! is_array($raw)) {
            return $defaults;
        }

        return [
            'prefer_generated_audio' => isset($raw['prefer_generated_audio'])
                ? (bool) $raw['prefer_generated_audio']
                : $defaults['prefer_generated_audio'],
            'allow_custom_pronunciation' => isset($raw['allow_custom_pronunciation'])
                ? (bool) $raw['allow_custom_pronunciation']
                : $defaults['allow_custom_pronunciation'],
            'segment_2_enabled' => isset($raw['segment_2_enabled'])
                ? (bool) $raw['segment_2_enabled']
                : $defaults['segment_2_enabled'],
        ];
    }
}
