<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TokenTtsSetting extends Model
{
    protected $fillable = [
        'voice_id',
        'rate',
    ];

    protected $casts = [
        'rate' => 'float',
    ];

    /**
     * Get the singleton token TTS settings. Creates default row if none exists.
     */
    public static function instance(): self
    {
        $settings = self::first();
        if (! $settings) {
            $settings = self::create([
                'voice_id' => null,
                'rate' => (float) config('tts.default_rate', 0.84),
            ]);
        }

        return $settings;
    }

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
}

