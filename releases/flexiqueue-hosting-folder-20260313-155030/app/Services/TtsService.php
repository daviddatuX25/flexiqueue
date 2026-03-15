<?php

namespace App\Services;

use App\Models\Token;
use App\Models\TtsAccount;
use App\Support\TtsPhrase;
use Illuminate\Support\Facades\Storage;

/**
 * Server-side TTS: generate or serve cached audio. Keyed by hash(text + voice_id + rate).
 * Per plan: one driver (ElevenLabs); file cache under storage.
 * Credentials resolved from TtsAccount (active) first, then config.
 */
class TtsService
{
    private const CACHE_EXT = '.mp3';

    public function __construct(
        private readonly string $driver,
        private readonly string $defaultVoiceId,
        private readonly float $defaultRate,
        private readonly string $cachePath
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            config('tts.driver', 'null'),
            config('tts.default_voice_id', ''),
            (float) config('tts.default_rate', 0.84),
            config('tts.cache_path', 'app/tts')
        );
    }

    /** Whether server TTS is configured and usable. */
    public function isEnabled(): bool
    {
        if ($this->driver === 'null' || $this->driver === '') {
            return false;
        }
        if ($this->driver === 'elevenlabs') {
            return $this->getResolvedApiKey() !== '';
        }

        return false;
    }

    /** Resolve API key: active TtsAccount first, then config. */
    public function getResolvedApiKey(): string
    {
        $active = TtsAccount::getActive();
        if ($active !== null) {
            $key = $active->getApiKey();
            if ($key !== '') {
                return $key;
            }
        }

        return (string) config('tts.elevenlabs.api_key', '');
    }

    /** Resolve model ID: active TtsAccount first, then config. */
    public function getResolvedModelId(): string
    {
        $active = TtsAccount::getActive();
        if ($active !== null && $active->model_id !== '') {
            return $active->model_id;
        }

        return (string) config('tts.elevenlabs.model_id', 'eleven_multilingual_v2');
    }

    /**
     * Get path to audio file (from cache or generate). Returns null on failure or when disabled.
     *
     * @param  non-empty-string  $voiceId  Engine voice ID (e.g. ElevenLabs voice_id)
     */
    public function getPath(string $text, string $voiceId, float $rate): ?string
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
            return Storage::path($path);
        }

        $content = $this->generate($text, $voiceId, $rate);
        if ($content === null) {
            return null;
        }

        Storage::makeDirectory($this->cachePath);
        Storage::put($path, $content);

        return Storage::path($path);
    }

    /**
     * Generate audio via driver. Returns raw audio bytes or null.
     *
     * @param  non-empty-string  $voiceId
     */
    private function generate(string $text, string $voiceId, float $rate): ?string
    {
        if ($this->driver === 'elevenlabs') {
            return $this->generateElevenLabs($text, $voiceId, $rate);
        }

        return null;
    }

    /**
     * Per REFACTORING-ISSUE-LIST Issue 16 / flexiqueue-g693: delegate to ElevenLabsClient.
     */
    private function generateElevenLabs(string $text, string $voiceId, float $rate): ?string
    {
        $apiKey = $this->getResolvedApiKey();
        if ($apiKey === '') {
            return null;
        }

        $voiceSettings = $rate !== 1.0 ? ['stability' => 0.5, 'similarity_boost' => 0.75] : null;
        $client = new ElevenLabsClient($apiKey);

        return $client->generateSpeech($text, $voiceId, $this->getResolvedModelId(), $voiceSettings);
    }

    private function cacheKey(string $text, string $voiceId, float $rate): string
    {
        return hash('sha256', $text."\n".$voiceId."\n".((string) $rate));
    }

    /** Voices for admin dropdown: from ElevenLabs API when key available, else config fallback. */
    public function getVoicesList(): array
    {
        $apiKey = $this->getResolvedApiKey();
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
     */
    public function storeSegment(string $text, ?string $voiceId, ?float $rate, string $relativePath): ?string
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

        $cachePath = $this->getPath($text, $voiceId, $rate);
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
        $phrase = TtsPhrase::buildCallPhraseForToken($token);
        $tokenPath = 'tts/tokens/'.$token->id.'.mp3';
        $stored = $this->storeSegment($phrase, $voiceId, $rate, $tokenPath);
        if ($stored === null) {
            return null;
        }

        $token->update(['tts_audio_path' => $stored]);

        return $stored;
    }
}
