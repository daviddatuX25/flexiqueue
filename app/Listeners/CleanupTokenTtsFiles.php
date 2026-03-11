<?php

namespace App\Listeners;

use App\Events\TokenDeleted;
use Illuminate\Support\Facades\Storage;

/**
 * Per docs/REFACTORING-ISSUE-LIST.md Issues 11–12: remove token TTS files on delete.
 * File I/O moved out of Token model booted() into this listener.
 */
class CleanupTokenTtsFiles
{
    public function handle(TokenDeleted $event): void
    {
        $token = $event->token;

        $baseDir = 'tts/tokens/'.$token->id;
        if (Storage::exists($baseDir)) {
            Storage::deleteDirectory($baseDir);
        }

        if ($token->tts_audio_path && Storage::exists($token->tts_audio_path)) {
            Storage::delete($token->tts_audio_path);
        }

        if ($token->tts_settings && is_array($token->tts_settings) && isset($token->tts_settings['languages']) && is_array($token->tts_settings['languages'])) {
            foreach ($token->tts_settings['languages'] as $lang => $config) {
                if (! empty($config['audio_path']) && Storage::exists($config['audio_path'])) {
                    Storage::delete($config['audio_path']);
                }
            }
        }
    }
}
