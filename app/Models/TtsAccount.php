<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Server-side TTS integration account (`provider` matches `config('tts.driver')` for synthesis).
 * At most one {@see $is_active} row per {@see $provider} (see ADR 001).
 * When no matching account: fall back to `config('tts.*')` for the current driver.
 */
class TtsAccount extends Model
{
    protected $fillable = [
        'label',
        'provider',
        'api_key',
        'model_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'api_key',
    ];

    /**
     * Scope: active account only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Active account for a given provider (e.g. `elevenlabs`), if any.
     */
    public static function getActiveForProvider(string $provider): ?self
    {
        return self::active()->where('provider', $provider)->first();
    }

    /**
     * First active row (legacy). Prefer {@see getActiveForProvider()} when multiple providers exist.
     */
    public static function getActive(): ?self
    {
        return self::active()->first();
    }

    /**
     * Active account for {@see config('tts.driver')} only.
     * Prevents using e.g. an ElevenLabs-stored key when the app driver is a different engine.
     */
    public static function getActiveMatchingDriver(): ?self
    {
        $driver = (string) config('tts.driver', 'null');
        if ($driver === 'null' || $driver === '') {
            return null;
        }

        return self::getActiveForProvider($driver);
    }

    /**
     * Get decrypted API key. Never expose in API responses.
     */
    public function getApiKey(): string
    {
        $raw = $this->attributes['api_key'] ?? $this->getRawOriginal('api_key') ?? '';
        if ($raw === '' || ! is_string($raw)) {
            return '';
        }

        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Set encrypted API key when assigning.
     */
    public function setApiKeyAttribute(?string $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['api_key'] = '';

            return;
        }

        $this->attributes['api_key'] = Crypt::encryptString($value);
    }

    /**
     * Masked API key hint for display (never expose full key).
     */
    public function getMaskedApiKey(): string
    {
        $key = $this->getApiKey();
        if (strlen($key) <= 8) {
            return '••••••••';
        }

        return substr($key, 0, 4).'…'.substr($key, -4);
    }

    /**
     * Sanitized representation for API responses (no api_key).
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'provider' => $this->provider ?? 'elevenlabs',
            'model_id' => $this->model_id,
            'is_active' => $this->is_active,
            'masked_api_key' => $this->getMaskedApiKey(),
        ];
    }
}
