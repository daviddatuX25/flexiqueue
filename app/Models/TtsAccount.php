<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * ElevenLabs TTS account. At most one is_active. Used when no DB account: fall back to config.
 */
class TtsAccount extends Model
{
    protected $fillable = [
        'label',
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
     * Get the active TTS account, if any.
     */
    public static function getActive(): ?self
    {
        return self::active()->first();
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
     * Set this account as active; deactivate others.
     */
    public function activate(): void
    {
        static::where('id', '!=', $this->id)->update(['is_active' => false]);
        $this->update(['is_active' => true]);
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
            'model_id' => $this->model_id,
            'is_active' => $this->is_active,
            'masked_api_key' => $this->getMaskedApiKey(),
        ];
    }
}
