<?php

namespace App\Services;

use App\Models\Token;
use App\Support\TtsPhrase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Server-side TTS: generate or serve cached audio. Keyed by hash(text + voice_id + rate).
 * Per plan: one driver (ElevenLabs); file cache under storage.
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
            return (string) config('tts.elevenlabs.api_key', '') !== '';
        }

        return false;
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

    private function generateElevenLabs(string $text, string $voiceId, float $rate): ?string
    {
        $apiKey = config('tts.elevenlabs.api_key', '');
        if ($apiKey === '') {
            return null;
        }

        $url = 'https://api.elevenlabs.io/v1/text-to-speech/'.urlencode($voiceId);

        $body = [
            'text' => $text,
            'model_id' => config('tts.elevenlabs.model_id', 'eleven_multilingual_v2'),
        ];
        if ($rate !== 1.0) {
            $body['voice_settings'] = ['stability' => 0.5, 'similarity_boost' => 0.75];
            // ElevenLabs does not support rate in same way; we send as-is, engine may ignore
        }

        $response = Http::withHeaders([
            'xi-api-key' => $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'audio/mpeg',
        ])->timeout(30)->post($url, $body);

        if (! $response->successful()) {
            return null;
        }

        return $response->body();
    }

    private function cacheKey(string $text, string $voiceId, float $rate): string
    {
        return hash('sha256', $text."\n".$voiceId."\n".((string) $rate));
    }

    /** Config-driven list for admin dropdown. */
    public function getVoicesList(): array
    {
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
     * Generate TTS for a token and store under tts/tokens/{id}.mp3. Updates token.tts_audio_path.
     * Returns stored relative path or null on failure.
     */
    public function storeTokenTts(Token $token): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $phrase = TtsPhrase::buildCallPhraseForToken($token);
        $path = $this->getPath($phrase, $this->defaultVoiceId, $this->defaultRate);
        if ($path === null || ! is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $tokenPath = 'tts/tokens/'.$token->id.'.mp3';
        Storage::makeDirectory('tts/tokens');
        Storage::put($tokenPath, $content);

        $token->update(['tts_audio_path' => $tokenPath]);

        return $tokenPath;
    }
}
