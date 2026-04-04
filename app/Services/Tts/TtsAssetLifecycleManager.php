<?php

namespace App\Services\Tts;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TtsAssetLifecycleManager
{
    /**
     * @param  array<string,mixed>  $config
     * @param  array{canonical_key:string,storage_path:string,revision:int,hash:string}  $identity
     * @return array<string,mixed>
     */
    public function markReady(array $config, array $identity): array
    {
        $previousPath = isset($config['audio_path']) && is_string($config['audio_path']) ? $config['audio_path'] : null;
        $newPath = $identity['storage_path'];
        $meta = $this->normalizeMeta($config['asset_meta'] ?? []);

        if ($previousPath !== null && $previousPath !== '' && $previousPath !== $newPath && Storage::exists($previousPath)) {
            Storage::delete($previousPath);
            $meta['replaced_paths'][] = [
                'path' => $previousPath,
                'replaced_at' => now()->toIso8601String(),
            ];
            $meta['replaced_paths'] = array_slice($meta['replaced_paths'], -5);
        }

        $meta['canonical_key'] = $identity['canonical_key'];
        $meta['hash'] = $identity['hash'];
        $meta['revision'] = $identity['revision'];
        $meta['generated_at'] = now()->toIso8601String();

        $config['audio_path'] = $newPath;
        $config['status'] = 'ready';
        $config['failure_reason'] = null;
        $config['asset_meta'] = $meta;
        if ((bool) config('tts.runtime_diagnostics_enabled', false)) {
            Log::info('tts.asset.lifecycle.ready', [
                'canonical_key' => $identity['canonical_key'],
                'revision' => $identity['revision'],
                'path' => $newPath,
            ]);
        }

        return $config;
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    public function markFailed(array $config, string $reason): array
    {
        $config['status'] = 'failed';
        $config['failure_reason'] = $reason;
        $meta = $this->normalizeMeta($config['asset_meta'] ?? []);
        $meta['failed_at'] = now()->toIso8601String();
        $config['asset_meta'] = $meta;
        if ((bool) config('tts.runtime_diagnostics_enabled', false)) {
            Log::info('tts.asset.lifecycle.failed', [
                'canonical_key' => $meta['canonical_key'] ?? null,
                'reason' => $reason,
            ]);
        }

        return $config;
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    public function markGenerating(array $config, array $identity): array
    {
        $config['status'] = 'generating';
        $meta = $this->normalizeMeta($config['asset_meta'] ?? []);
        $meta['canonical_key'] = $identity['canonical_key'];
        $meta['hash'] = $identity['hash'];
        $meta['revision'] = $identity['revision'];
        $meta['started_at'] = now()->toIso8601String();
        $config['asset_meta'] = $meta;
        if ((bool) config('tts.runtime_diagnostics_enabled', false)) {
            Log::info('tts.asset.lifecycle.generating', [
                'canonical_key' => $identity['canonical_key'],
                'revision' => $identity['revision'],
            ]);
        }

        return $config;
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeMeta(mixed $meta): array
    {
        if (! is_array($meta)) {
            $meta = [];
        }

        if (! isset($meta['replaced_paths']) || ! is_array($meta['replaced_paths'])) {
            $meta['replaced_paths'] = [];
        }

        return $meta;
    }
}
