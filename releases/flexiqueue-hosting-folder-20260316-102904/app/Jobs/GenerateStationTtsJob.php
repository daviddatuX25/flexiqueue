<?php

namespace App\Jobs;

use App\Events\StationTtsStatusUpdated;
use App\Models\Program;
use App\Models\Station;
use App\Services\DisplayBoardService;
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

    public function handle(TtsService $ttsService, DisplayBoardService $displayBoardService): void
    {
        if (! $ttsService->isEnabled()) {
            return;
        }

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
                $text = $displayBoardService->getSecondSegmentText($program, $station, $lang);
                if (trim($text) === '') {
                    $config['status'] = 'failed';
                    $languagesConfig[$lang] = $config;
                    continue;
                }

                $relativePath = 'tts/stations/'.$station->id.'/'.$lang.'.mp3';
                $stored = $ttsService->storeSegment($text, $voiceId, $rate, $relativePath);

                if ($stored !== null) {
                    $config['audio_path'] = $stored;
                    $config['status'] = 'ready';
                } else {
                    $config['status'] = 'failed';
                }
            } catch (\Throwable $e) {
                Log::warning('Station TTS generation failed for station {id} lang {lang}: {message}', [
                    'id' => $station->id,
                    'lang' => $lang,
                    'message' => $e->getMessage(),
                ]);
                $config['status'] = 'failed';
            }

            $languagesConfig[$lang] = $config;
        }

        $settings['tts']['languages'] = $languagesConfig;
        $station->settings = $settings;
        $station->save();

        StationTtsStatusUpdated::dispatch($station->fresh());
    }
}
