<?php

namespace App\Services\Tts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TtsGenerationLock
{
    public function run(string $key, callable $callback, int $seconds = 60): bool
    {
        $lock = Cache::lock('tts:generate:'.$key, $seconds);

        if (! $lock->get()) {
            if ((bool) config('tts.runtime_diagnostics_enabled', false)) {
                Log::info('tts.generation_lock.contended', [
                    'lock_key' => $key,
                    'ttl_seconds' => $seconds,
                ]);
            }

            return false;
        }

        try {
            if ((bool) config('tts.runtime_diagnostics_enabled', false)) {
                Log::info('tts.generation_lock.acquired', [
                    'lock_key' => $key,
                    'ttl_seconds' => $seconds,
                ]);
            }
            $callback();
        } finally {
            $lock->release();
            if ((bool) config('tts.runtime_diagnostics_enabled', false)) {
                Log::info('tts.generation_lock.released', [
                    'lock_key' => $key,
                ]);
            }
        }

        return true;
    }
}
