<?php

namespace Tests\Unit\Services\Tts;

use App\Services\Tts\TtsLanguageStatusPresenter;
use Tests\TestCase;

class TtsLanguageStatusPresenterTest extends TestCase
{
    public function test_present_preserves_revision_ready_asset_meta_and_normalizes_shape(): void
    {
        $presenter = app(TtsLanguageStatusPresenter::class);

        $result = $presenter->present([
            'en' => [
                'audio_path' => 'tts/tokens/10/en/r2-test-abcd1234.mp3',
                'status' => 'ready',
                'asset_meta' => [
                    'canonical_key' => 'token|10|en|calling a 1|voice-1|0.840|r:2',
                    'revision' => 2,
                    'hash' => 'abcd1234',
                ],
            ],
        ]);

        $this->assertArrayHasKey('en', $result);
        $this->assertArrayHasKey('fil', $result);
        $this->assertArrayHasKey('ilo', $result);
        $this->assertSame('ready', $result['en']['status']);
        $this->assertSame(2, $result['en']['asset_meta']['revision']);
        $this->assertNull($result['fil']['status']);
        $this->assertNull($result['ilo']['audio_path']);
    }
}
