<?php

namespace App\Listeners;

use App\Events\StationDeleted;
use Illuminate\Support\Facades\Storage;

/**
 * Per docs/REFACTORING-ISSUE-LIST.md Issues 11–12: remove station TTS files on delete.
 * File I/O moved out of Station model booted() into this listener.
 */
class CleanupStationTtsFiles
{
    public function handle(StationDeleted $event): void
    {
        $station = $event->station;

        $baseDir = 'tts/stations/'.$station->id;
        if (Storage::exists($baseDir)) {
            Storage::deleteDirectory($baseDir);
        }

        $settings = $station->settings ?? [];
        if (isset($settings['tts']['languages']) && is_array($settings['tts']['languages'])) {
            foreach ($settings['tts']['languages'] as $lang => $config) {
                if (! empty($config['audio_path']) && Storage::exists($config['audio_path'])) {
                    Storage::delete($config['audio_path']);
                }
            }
        }
    }
}
