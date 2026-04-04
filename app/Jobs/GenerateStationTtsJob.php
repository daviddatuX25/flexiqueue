<?php

namespace App\Jobs;

use App\Events\StationTtsStatusUpdated;
use App\Models\Program;
use App\Models\Station;
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
 * Generate and store second-segment TTS audio for a station (connector + station phrase per language).
 * Updates station.settings['tts']['languages'][lang] with audio_path and status.
 * Dispatched after station create/update and when program connector TTS changes.
 */
class GenerateStationTtsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Station $station
    ) {}

    public function handle(
        TtsService $ttsService,
        AnnouncementBuilder $announcementBuilder,
        TokenTtsSettingRepository $tokenTtsSettingRepository,
        TtsAssetIdentity $assetIdentity,
        TtsAssetLifecycleManager $lifecycleManager,
        TtsGenerationLock $generationLock
    ): void {
        if (! $ttsService->isEnabled()) {
            return;
        }

        $tokenTtsSite = $tokenTtsSettingRepository->getInstance();

        $station = $this->station->fresh();
        if (! $station) {
            return;
        }

        $station->loadMissing('program');
        $program = $station->program;
        if (! $program) {
            return;
        }

        $settings = $station->settings ?? [];
        $languagesConfig = $settings['tts']['languages'] ?? [];
        $programSettings = $program->settings ?? [];
        $connectorLangs = $programSettings['tts']['connector']['languages'] ?? [];

        $languages = ['en', 'fil', 'ilo'];
        $defaultVoiceId = config('tts.default_voice_id');
        $defaultRate = (float) config('tts.default_rate', 0.84);

        foreach ($languages as $lang) {
            $config = $languagesConfig[$lang] ?? [];
            $connectorConfig = $connectorLangs[$lang] ?? [];
            $voiceId = $config['voice_id'] ?? $connectorConfig['voice_id'] ?? $defaultVoiceId;
            $voiceId = (isset($voiceId) && $voiceId !== '') ? (string) $voiceId : $defaultVoiceId;
            $rate = isset($config['rate']) ? (float) $config['rate'] : (isset($connectorConfig['rate']) ? (float) $connectorConfig['rate'] : $defaultRate);

            if ($voiceId === null || $voiceId === '') {
                $config['status'] = 'failed';
                $languagesConfig[$lang] = $config;

                continue;
            }

            try {
                $text = $announcementBuilder->buildSegment2($station, $program, $lang, $tokenTtsSite);
                if (trim($text) === '') {
                    $config = $lifecycleManager->markFailed($config, 'empty_segment2_phrase');
                    $languagesConfig[$lang] = $config;

                    continue;
                }

                $revision = (int) data_get($config, 'asset_meta.revision', 0) + 1;
                $identity = $assetIdentity->build(
                    'station',
                    (int) $station->id,
                    $lang,
                    $text,
                    $voiceId,
                    $rate,
                    max(1, $revision),
                    $ttsService->getProviderKey(),
                    $ttsService->getAssetIdentityModelKey()
                );
                $config = $lifecycleManager->markGenerating($config, $identity);

                $lockSeconds = max(15, (int) config('tts.generation_lock_seconds', 90));
                $siteId = $program->site_id ?? null;
                $lockAcquired = $generationLock->run($identity['canonical_key'], function () use (&$config, $ttsService, $text, $voiceId, $rate, $identity, $lifecycleManager, $siteId): void {
                    $stored = $ttsService->storeSegment($text, $voiceId, $rate, $identity['storage_path'], $siteId, 'job');
                    if ($stored !== null) {
                        $config = $lifecycleManager->markReady($config, $identity);
                    } else {
                        $config = $lifecycleManager->markFailed($config, 'generation_failed');
                    }
                }, $lockSeconds);

                if (! $lockAcquired) {
                    $config = $lifecycleManager->markFailed($config, 'generation_in_progress');
                }
            } catch (\Throwable $e) {
                Log::warning('Station TTS generation failed for station {id} lang {lang}: {message}', [
                    'id' => $station->id,
                    'lang' => $lang,
                    'message' => $e->getMessage(),
                ]);
                $config = $lifecycleManager->markFailed($config, $e->getMessage());
            }

            $languagesConfig[$lang] = $config;
        }

        $settings['tts']['languages'] = $languagesConfig;
        $station->settings = $settings;
        $station->save();

        StationTtsStatusUpdated::dispatch($station->fresh());
    }
}
