<?php

namespace App\Jobs;

use App\Models\Token;
use App\Models\TokenTtsSetting;
use App\Services\TtsService;
use App\Support\TtsPhrase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generate and store TTS audio for tokens. Dispatched after batch create when generate_tts is true.
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

    public function handle(TtsService $ttsService): void
    {
        if (! $ttsService->isEnabled()) {
            return;
        }

        $settings = TokenTtsSetting::instance();
        $defaultVoiceId = $settings->getEffectiveVoiceId();
        $defaultRate = $settings->getEffectiveRate();

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
                    $config = $languagesConfig[$lang] ?? [];

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
                        ? TtsPhrase::aliasForSpeech($token->physical_id ?? 'client', $token->pronounce_as ?? 'letters')
                        : TtsPhrase::buildCallPhraseForToken($token);

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
                $token->tts_settings = $settingsArray;

                if ($token->tts_pre_generate_enabled) {
                    $token->tts_status = $hadSuccess ? 'pre_generated' : 'failed';
                }

                $token->save();
            } catch (\Throwable $e) {
                if ($token->tts_pre_generate_enabled) {
                    $token->tts_status = 'failed';
                    $token->save();
                }
                Log::warning('TTS generation failed for token {id}: {message}', [
                    'id' => $token->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
