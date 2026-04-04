<?php

namespace Tests\Unit\Services\Tts;

use App\Services\Tts\TtsAssetLifecycleManager;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TtsAssetLifecycleManagerTest extends TestCase
{
    public function test_mark_ready_replaces_old_audio_and_records_metadata(): void
    {
        Storage::fake('local');
        Storage::put('tts/tokens/1/en/old.mp3', 'old-audio');

        $manager = app(TtsAssetLifecycleManager::class);
        $currentConfig = [
            'audio_path' => 'tts/tokens/1/en/old.mp3',
            'asset_meta' => [
                'revision' => 1,
            ],
        ];
        $identity = [
            'canonical_key' => 'token:1:en:key',
            'storage_path' => 'tts/tokens/1/en/new.mp3',
            'revision' => 2,
            'hash' => 'abc123',
        ];

        $updated = $manager->markReady($currentConfig, $identity);

        $this->assertSame('ready', $updated['status']);
        $this->assertSame('tts/tokens/1/en/new.mp3', $updated['audio_path']);
        $this->assertSame(2, $updated['asset_meta']['revision']);
        $this->assertSame('token:1:en:key', $updated['asset_meta']['canonical_key']);
        $this->assertFalse(Storage::exists('tts/tokens/1/en/old.mp3'));
        $this->assertNotEmpty($updated['asset_meta']['replaced_paths']);
    }
}
