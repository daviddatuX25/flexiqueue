<?php

namespace Tests\Unit\Services\Tts;

use App\Services\Tts\TtsAssetIdentity;
use Tests\TestCase;

class TtsAssetIdentityTest extends TestCase
{
    public function test_build_returns_deterministic_identity_for_same_input(): void
    {
        $identity = app(TtsAssetIdentity::class);

        $a = $identity->build(
            scope: 'token',
            entityId: 10,
            language: 'en',
            phrase: 'Calling A 1',
            voiceId: 'voice-1',
            rate: 0.84,
            revision: 1
        );
        $b = $identity->build(
            scope: 'token',
            entityId: 10,
            language: 'en',
            phrase: '  Calling   A 1  ',
            voiceId: 'voice-1',
            rate: 0.84,
            revision: 1
        );

        $this->assertSame($a['canonical_key'], $b['canonical_key']);
        $this->assertSame($a['storage_path'], $b['storage_path']);
    }

    public function test_build_changes_identity_when_revision_changes(): void
    {
        $identity = app(TtsAssetIdentity::class);

        $v1 = $identity->build('station', 5, 'fil', 'Proceed to window 2', 'voice-2', 1.0, 1);
        $v2 = $identity->build('station', 5, 'fil', 'Proceed to window 2', 'voice-2', 1.0, 2);

        $this->assertNotSame($v1['canonical_key'], $v2['canonical_key']);
        $this->assertNotSame($v1['storage_path'], $v2['storage_path']);
    }

    public function test_build_includes_provider_and_model_when_provided(): void
    {
        $identity = app(TtsAssetIdentity::class);

        $legacy = $identity->build('token', 1, 'en', 'Hello', 'v1', 0.84, 1);
        $scoped = $identity->build('token', 1, 'en', 'Hello', 'v1', 0.84, 1, 'elevenlabs', 'eleven_multilingual_v2');

        $this->assertStringNotContainsString('p:elevenlabs', $legacy['canonical_key']);
        $this->assertStringContainsString('p:elevenlabs', $scoped['canonical_key']);
        $this->assertStringContainsString('m:eleven_multilingual_v2', $scoped['canonical_key']);
        $this->assertNotSame($legacy['canonical_key'], $scoped['canonical_key']);
    }
}
