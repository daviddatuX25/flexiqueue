<?php

namespace App\Services;

use App\Models\Token;
use App\Models\TtsAccount;
use App\Repositories\TokenTtsSettingRepository;
use App\Services\Tts\AnnouncementBuilder;
use App\Services\Tts\Contracts\TtsEngine;
use App\Services\Tts\DTO\SynthesisRequest;
use App\Services\Tts\ElevenLabsCredentials;
use App\Services\Tts\TtsBudgetGuard;
use App\Services\Tts\TtsGenerationMeter;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Server-side TTS: generate or serve cached audio. Keyed by hash(engine identity + text + voice_id + rate).
 * Synthesis delegates to {@see TtsEngine}; credentials resolved from TtsAccount (active + matching driver) first, then config.
 */
class TtsService
{
    private const CACHE_EXT = '.mp3';

    public function __construct(
        private readonly TtsEngine $engine,
        private readonly string $defaultVoiceId,
        private readonly float $defaultRate,
        private readonly string $cachePath,
        private readonly ?TtsGenerationMeter $meter = null,
        private readonly ?TtsBudgetGuard $budgetGuard = null
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            App::make(TtsEngine::class),
            config('tts.default_voice_id', ''),
            (float) config('tts.default_rate', 0.84),
            config('tts.cache_path', 'app/tts'),
            App::make(TtsGenerationMeter::class),
            App::make(TtsBudgetGuard::class)
        );
    }

    public function getProviderKey(): string
    {
        return $this->engine->getProviderKey();
    }

    public function getAssetIdentityModelKey(): string
    {
        return $this->engine->getAssetIdentityModelKey();
    }

    /** Whether server TTS is configured and usable. */
    public function isEnabled(): bool
    {
        return $this->engine->isConfigured();
    }

    /** Resolve API key: active matching account first, then config (ElevenLabs only). */
    public function getResolvedApiKey(): string
    {
        if ($this->engine->getProviderKey() !== 'elevenlabs') {
            return '';
        }

        return ElevenLabsCredentials::resolveApiKey();
    }

    /** Resolve model ID: active matching account first, then config (ElevenLabs only). */
    public function getResolvedModelId(): string
    {
        if ($this->engine->getProviderKey() !== 'elevenlabs') {
            return '';
        }

        return ElevenLabsCredentials::resolveModelId();
    }

    /**
     * Get path to audio file (from cache or generate). Returns null on failure or when disabled.
     *
     * @param  non-empty-string  $voiceId  Engine voice ID (e.g. ElevenLabs voice_id)
     * @param  string  $source  Metering source: 'job' | 'preview'
     */
    public function getPath(string $text, string $voiceId, float $rate, ?int $siteId = null, string $source = 'preview'): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (! $this->isEnabled()) {
            return null;
        }

        $voiceId = $voiceId !== '' ? $voiceId : $this->defaultVoiceId;
        if ($voiceId === '') {
            return null;
        }

        $key = $this->cacheKey($text, $voiceId, $rate);
        $path = $this->cachePath.'/'.$key.self::CACHE_EXT;

        if (Storage::exists($path)) {
            if (config('tts.runtime_diagnostics_enabled', false)) {
                Log::debug('tts.cache_hit', [
                    'provider' => $this->engine->getProviderKey(),
                    'cache_hit' => true,
                ]);
            }

            return Storage::path($path);
        }

        if (config('tts.runtime_diagnostics_enabled', false)) {
            Log::debug('tts.cache_miss', [
                'provider' => $this->engine->getProviderKey(),
                'cache_hit' => false,
            ]);
        }

        $lockScope = $this->budgetGuard?->lockScope($siteId);
        if ($lockScope !== null) {
            try {
                return Cache::lock('tts:budget:'.$lockScope, 15)->block(
                    5,
                    fn () => $this->generateAndStorePath($text, $voiceId, $rate, $siteId, $source, $path)
                );
            } catch (LockTimeoutException) {
                Log::notice('tts.budget_lock_timeout', [
                    'provider' => $this->engine->getProviderKey(),
                    'site_id' => $siteId,
                    'scope' => $lockScope,
                ]);

                return null;
            }
        }

        return $this->generateAndStorePath($text, $voiceId, $rate, $siteId, $source, $path);
    }

    private function generateAndStorePath(string $text, string $voiceId, float $rate, ?int $siteId, string $source, string $path): ?string
    {
        $charsToAdd = mb_strlen($text);
        if ($this->budgetGuard !== null && ! $this->budgetGuard->canGenerate($siteId, $charsToAdd)) {
            Log::notice('tts.budget_exceeded', [
                'provider' => $this->engine->getProviderKey(),
                'site_id' => $siteId,
            ]);

            return null;
        }

        $result = $this->generateWithUsage($text, $voiceId, $rate);
        if ($result === null) {
            Log::notice('tts.synthesis_failed', [
                'provider' => $this->engine->getProviderKey(),
                'outcome' => 'provider_error',
            ]);

            return null;
        }

        Storage::makeDirectory($this->cachePath);
        Storage::put($path, $result['bytes']);

        if ($this->meter !== null) {
            $chars = $result['chars'];
            $this->meter->record($siteId, $this->engine->getProviderKey(), $chars, $source);
        }

        return Storage::path($path);
    }

    /**
     * Generate audio via engine. Returns ['bytes' => string, 'chars' => int] or null.
     *
     * @param  non-empty-string  $voiceId
     * @return array{bytes: string, chars: int}|null
     */
    private function generateWithUsage(string $text, string $voiceId, float $rate): ?array
    {
        $request = new SynthesisRequest($text, $voiceId, $rate);
        $result = $this->engine->synthesize($request);

        if ($result === null) {
            return null;
        }

        $chars = isset($result->usage['chars']) ? (int) $result->usage['chars'] : mb_strlen($text);

        return [
            'bytes' => $result->audioBytes,
            'chars' => $chars,
        ];
    }

    private function cacheKey(string $text, string $voiceId, float $rate): string
    {
        $segment = $this->engine->getCacheIdentitySegment();

        return hash('sha256', $segment."\n".$text."\n".$voiceId."\n".((string) $rate));
    }

    /** Voices for admin dropdown: from active engine when configured, else config fallback. */
    public function getVoicesList(): array
    {
        return $this->engine->listVoices();
    }

    public function getDefaultVoiceId(): string
    {
        return $this->defaultVoiceId;
    }

    public function getDefaultRate(): float
    {
        return $this->defaultRate;
    }

    /**
     * Generate TTS for an arbitrary phrase and store it under the given relative storage path.
     * Returns the stored relative path or null on failure.
     *
     * @param  string|null  $voiceId  Engine voice ID; null = use default
     * @param  float|null  $rate  Playback rate; null = use default
     * @param  string  $source  Metering source: 'job' | 'preview'
     */
    public function storeSegment(string $text, ?string $voiceId, ?float $rate, string $relativePath, ?int $siteId = null, string $source = 'job'): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $voiceId = $voiceId ?? $this->defaultVoiceId;
        $rate = $rate ?? $this->defaultRate;

        $cachePath = $this->getPath($text, $voiceId, $rate, $siteId, $source);
        if ($cachePath === null || ! is_file($cachePath)) {
            return null;
        }

        $content = file_get_contents($cachePath);
        if ($content === false) {
            return null;
        }

        $directory = trim(dirname($relativePath), '/');
        if ($directory !== '' && $directory !== '.') {
            Storage::makeDirectory($directory);
        }

        Storage::put($relativePath, $content);

        return $relativePath;
    }

    /**
     * Generate TTS for a token and store under tts/tokens/{id}.mp3. Updates token.tts_audio_path.
     * Returns stored relative path or null on failure.
     *
     * @param  string|null  $voiceId  Engine voice ID; null = use default
     * @param  float|null  $rate  Playback rate; null = use default
     */
    public function storeTokenTts(Token $token, ?string $voiceId = null, ?float $rate = null): ?string
    {
        $site = app(TokenTtsSettingRepository::class)->getInstance();
        $phrase = app(AnnouncementBuilder::class)->buildSegment1($token, $site, 'en');
        $tokenPath = 'tts/tokens/'.$token->id.'.mp3';
        $stored = $this->storeSegment($phrase, $voiceId, $rate, $tokenPath, $token->site_id, 'job');
        if ($stored === null) {
            return null;
        }

        $token->update(['tts_audio_path' => $stored]);

        return $stored;
    }
}
