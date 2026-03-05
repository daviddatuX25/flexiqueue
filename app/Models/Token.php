<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Token extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'physical_id',
        'pronounce_as',
        'status',
        'current_session_id',
        'tts_audio_path',
        'tts_status',
        'tts_pre_generate_enabled',
        'tts_settings',
    ];

    /**
     * qr_code_hash is immutable after creation (per 04-DATA-MODEL).
     * Set directly when creating: $token->qr_code_hash = $hash; $token->save();
     */
    protected $guarded = ['qr_code_hash'];

    protected function casts(): array
    {
        return [
            'tts_settings' => 'array',
        ];
    }

    public function currentSession(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'current_session_id');
    }

    public function queueSessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    /**
     * Get per-language TTS settings array. Shape:
     * ['languages' => ['en' => [...], 'fil' => [...], 'ilo' => [...]]].
     */
    public function getTtsSettings(): array
    {
        $settings = $this->tts_settings ?? [];

        if (! is_array($settings)) {
            $settings = [];
        }

        if (! isset($settings['languages']) || ! is_array($settings['languages'])) {
            $settings['languages'] = [];
        }

        return $settings;
    }

    /**
     * Get TTS config for a specific language code (e.g. en, fil, ilo).
     */
    public function getTtsConfigFor(string $lang): array
    {
        $settings = $this->getTtsSettings();

        return $settings['languages'][$lang] ?? [];
    }

    /**
     * Set TTS config for a specific language code. Persists changes on the model instance only;
     * caller is responsible for saving.
     */
    public function setTtsConfigFor(string $lang, array $config): void
    {
        $settings = $this->getTtsSettings();
        $settings['languages'][$lang] = $config;
        $this->tts_settings = $settings;
    }

    /**
     * Convenience: update status for a given language entry.
     */
    public function setTtsStatusFor(string $lang, ?string $status): void
    {
        $config = $this->getTtsConfigFor($lang);
        $config['status'] = $status;
        $this->setTtsConfigFor($lang, $config);
    }

    protected static function booted(): void
    {
        static::deleted(function (self $token): void {
            // Remove any stored TTS audio under tts/tokens/{id} and legacy single-file path.
            $baseDir = 'tts/tokens/'.$token->id;
            if (Storage::exists($baseDir)) {
                Storage::deleteDirectory($baseDir);
            }

            if ($token->tts_audio_path && Storage::exists($token->tts_audio_path)) {
                Storage::delete($token->tts_audio_path);
            }

            if ($token->tts_settings) {
                $settings = $token->tts_settings;
                if (is_array($settings) && isset($settings['languages']) && is_array($settings['languages'])) {
                    foreach ($settings['languages'] as $lang => $config) {
                        if (! empty($config['audio_path']) && Storage::exists($config['audio_path'])) {
                            Storage::delete($config['audio_path']);
                        }
                        if (is_array($config)) {
                            $settings['languages'][$lang]['audio_path'] = null;
                            $settings['languages'][$lang]['status'] = null;
                        }
                    }
                    $token->tts_settings = $settings;
                }
            }
        });
    }
}

