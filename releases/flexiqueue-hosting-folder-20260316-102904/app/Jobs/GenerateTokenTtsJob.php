<?php

namespace App\Jobs;

use App\Events\TokenTtsStatusUpdated;
use App\Models\Token;
use App\Repositories\TokenTtsSettingRepository;
use App\Services\TtsService;
use App\Support\TtsPhrase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generate and store TTS audio for tokens.
 *
 * Per display-board contract, this job only pre-generates the **first**
 * segment of the call (token alias + optional pre-phrase). The second
 * segment ("connector phrase" + station/window name) is built at runtime
 * from Program/Station TTS settings and spoken separately on the display.
 *
 * Dispatched after batch create (and on explicit regenerate) when
 * tts_pre_generate_enabled is true.
 */
class GenerateTokenTtsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int>  $tokenIds
     */
    public function __construct(
        public array $tokenIds
    ) {}

    public function handle(TtsService $ttsService, TokenTtsSettingRepository $tokenTtsSettingRepository): void
    {
        try {
            $this->runHandle($ttsService, $tokenTtsSettingRepository);
        } catch (\Throwable $e) {
            $this->markGeneratingTokensAsFailed();
            Log::warning('GenerateTokenTtsJob failed: {message}', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function runHandle(TtsService $ttsService, TokenTtsSettingRepository $tokenTtsSettingRepository): void
    {
        if (! $ttsService->isEnabled()) {
            return;
        }

        $settings = $tokenTtsSettingRepository->getInstance();
        $defaultVoiceId = $settings->getEffectiveVoiceId();
        $defaultRate = $settings->getEffectiveRate();
        $defaultLanguages = $settings->getDefaultLanguages();

        $languages = ['en', 'fil', 'ilo'];

        foreach ($this->tokenIds as $tokenId) {
            $token = Token::find($tokenId);
            if (! $token) {
                continue;
            }

            try {
                if ($token->tts_pre_generate_enabled) {
                    $token->tts_status = 'generating';
                }

                $settingsArray = $token->getTtsSettings();
                $languagesConfig = $settingsArray['languages'] ?? [];

                $hadConfiguredLanguage = false;
                $hadSuccess = false;

                foreach ($languages as $lang) {
                    // Merge global default with token override (token wins).
                    $config = array_merge(
                        $defaultLanguages[$lang] ?? [],
                        $languagesConfig[$lang] ?? []
                    );

                    // Determine if this language is configured enough to attempt generation.
                    $hasConfig = array_key_exists('pre_phrase', $config)
                        || array_key_exists('voice_id', $config)
                        || array_key_exists('rate', $config)
                        || array_key_exists('audio_path', $config);

                    if (! $hasConfig) {
                        continue;
                    }

                    $hadConfiguredLanguage = true;

                    $voiceId = isset($config['voice_id']) && $config['voice_id'] !== ''
                        ? (string) $config['voice_id']
                        : $defaultVoiceId;

                    if ($voiceId === null) {
                        // No usable voice; mark failed for this language.
                        $config['status'] = 'failed';
                        $languagesConfig[$lang] = $config;
                        continue;
                    }

                    $rate = isset($config['rate']) ? (float) $config['rate'] : $defaultRate;

                    $prePhrase = isset($config['pre_phrase']) ? (string) $config['pre_phrase'] : '';
                    $text = $prePhrase !== ''
                        ? TtsPhrase::aliasForSpeech($token->physical_id ?? 'client', $token->pronounce_as ?? 'letters', $lang)
                        : TtsPhrase::buildCallPhraseForToken($token, $lang);

                    if ($prePhrase !== '') {
                        $text = trim($prePhrase.' '.$text);
                    }

                    $relativePath = 'tts/tokens/'.$token->id.'/'.$lang.'.mp3';
                    $stored = $ttsService->storeSegment($text, $voiceId, $rate, $relativePath);

                    if ($stored !== null) {
                        $config['audio_path'] = $stored;
                        $config['status'] = 'ready';
                        $languagesConfig[$lang] = $config;
                        $hadSuccess = true;
                    } else {
                        $config['status'] = 'failed';
                        $languagesConfig[$lang] = $config;
                    }
                }

                // Legacy path: no per-language config; fall back to single pre-generated file.
                if (! $hadConfiguredLanguage) {
                    if ($defaultVoiceId !== null) {
                        $storedPath = $ttsService->storeTokenTts($token, $defaultVoiceId, $defaultRate);
                        if ($storedPath !== null) {
                            $languagesConfig['en'] = array_merge(
                                [
                                    'voice_id' => $defaultVoiceId,
                                    'rate' => $defaultRate,
                                    'pre_phrase' => null,
                                ],
                                $languagesConfig['en'] ?? [],
                                [
                                    'audio_path' => $storedPath,
                                    'status' => 'ready',
                                ]
                            );
                            $hadSuccess = true;
                        }
                    }
                }

                $settingsArray['languages'] = $languagesConfig;
                $settingsArray['failure_reason'] = null;
                $token->tts_settings = $settingsArray;

                if ($token->tts_pre_generate_enabled) {
                    $token->tts_status = $hadSuccess ? 'pre_generated' : 'failed';
                }

                $token->save();
                try {
                    TokenTtsStatusUpdated::dispatch($token);
                } catch (\Throwable $e) {
                    // Reverb/websocket may be down; token is already saved with correct status — UI can rely on refresh.
                    Log::warning('TTS status broadcast failed for token {id} (Reverb may be down): {message}', [
                        'id' => $token->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            } catch (\Throwable $e) {
                if ($token->tts_pre_generate_enabled) {
                    $token->tts_status = 'failed';
                    $settings = $token->getTtsSettings();
                    $settings['failure_reason'] = $e->getMessage();
                    $token->tts_settings = $settings;
                    $token->save();
                    try {
                        TokenTtsStatusUpdated::dispatch($token);
                    } catch (\Throwable $broadcastEx) {
                        Log::debug('TTS status broadcast failed after generation failure', ['token_id' => $token->id]);
                    }
                }
                Log::warning('TTS generation failed for token {id}: {message}', [
                    'id' => $token->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Mark any tokens left in "generating" as "failed". Called when job fails (sync: outer catch;
     * queue: failed()) so UI shows correct status on refresh.
     */
    private function markGeneratingTokensAsFailed(): void
    {
        foreach ($this->tokenIds as $tokenId) {
            $token = Token::find($tokenId);
            if (! $token || $token->tts_status !== 'generating') {
                continue;
            }

            $token->tts_status = 'failed';
            $token->save();

            try {
                TokenTtsStatusUpdated::dispatch($token);
            } catch (\Throwable $e) {
                Log::debug('TTS status broadcast failed after mark failed', ['token_id' => $token->id]);
            }
        }
    }

    /**
     * Called when the job fails (timeout, uncaught exception, max attempts). With sync driver
     * this is NOT called; outer catch in handle() does cleanup. With queue, this runs.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('GenerateTokenTtsJob failed: {message}', ['message' => $exception->getMessage()]);
        $this->markGeneratingTokensAsFailed();
    }
}
