<?php

namespace App\Jobs;

use App\Events\TokenTtsStatusUpdated;
use App\Models\Token;
use App\Repositories\TokenTtsSettingRepository;
use App\Services\Tts\AnnouncementBuilder;
use App\Services\Tts\TtsAssetIdentity;
use App\Services\Tts\TtsAssetLifecycleManager;
use App\Services\Tts\TtsGenerationLock;
use App\Services\TtsService;
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

    public function handle(
        TtsService $ttsService,
        TokenTtsSettingRepository $tokenTtsSettingRepository,
        AnnouncementBuilder $announcementBuilder,
        TtsAssetIdentity $assetIdentity,
        TtsAssetLifecycleManager $lifecycleManager,
        TtsGenerationLock $generationLock,
    ): void {
        try {
            $this->runHandle($ttsService, $tokenTtsSettingRepository, $announcementBuilder, $assetIdentity, $lifecycleManager, $generationLock);
        } catch (\Throwable $e) {
            $this->markGeneratingTokensAsFailed();
            Log::warning('GenerateTokenTtsJob failed: {message}', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function runHandle(
        TtsService $ttsService,
        TokenTtsSettingRepository $tokenTtsSettingRepository,
        AnnouncementBuilder $announcementBuilder,
        TtsAssetIdentity $assetIdentity,
        TtsAssetLifecycleManager $lifecycleManager,
        TtsGenerationLock $generationLock,
    ): void {
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
                        || array_key_exists('token_phrase', $config)
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

                    $text = $announcementBuilder->buildSegment1($token, $settings, $lang, $config);
                    $revision = (int) data_get($config, 'asset_meta.revision', 0) + 1;
                    $identity = $assetIdentity->build(
                        'token',
                        (int) $token->id,
                        $lang,
                        $text,
                        $voiceId,
                        $rate,
                        max(1, $revision),
                        $ttsService->getProviderKey(),
                        $ttsService->getAssetIdentityModelKey()
                    );
                    $config = $lifecycleManager->markGenerating($config, $identity);
                    $languagesConfig[$lang] = $config;

                    $lockSeconds = max(15, (int) config('tts.generation_lock_seconds', 90));
                    $lockAcquired = $generationLock->run($identity['canonical_key'], function () use (&$config, &$hadSuccess, $ttsService, $text, $voiceId, $rate, $identity, $lifecycleManager, $token): void {
                        $stored = $ttsService->storeSegment($text, $voiceId, $rate, $identity['storage_path'], $token->site_id, 'job');
                        if ($stored !== null) {
                            $config = $lifecycleManager->markReady($config, $identity);
                            $hadSuccess = true;
                        } else {
                            $config = $lifecycleManager->markFailed($config, 'generation_failed');
                        }
                    }, $lockSeconds);

                    if (! $lockAcquired) {
                        $config = $lifecycleManager->markFailed($config, 'generation_in_progress');
                    }

                    $languagesConfig[$lang] = $config;
                }

                // Legacy path: no per-language config; fall back to single pre-generated file.
                if (! $hadConfiguredLanguage) {
                    if ($defaultVoiceId !== null) {
                        $mergedEn = array_merge($defaultLanguages['en'] ?? [], $languagesConfig['en'] ?? []);
                        $phrase = $announcementBuilder->buildSegment1($token, $settings, 'en', $mergedEn);
                        $tokenPath = 'tts/tokens/'.$token->id.'.mp3';
                        $storedPath = $ttsService->storeSegment($phrase, $defaultVoiceId, $defaultRate, $tokenPath, $token->site_id, 'job');
                        if ($storedPath !== null) {
                            $token->tts_audio_path = $storedPath;
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
