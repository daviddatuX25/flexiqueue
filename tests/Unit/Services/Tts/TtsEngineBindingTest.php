<?php

namespace Tests\Unit\Services\Tts;

use App\Services\Tts\Contracts\TtsEngine;
use App\Services\Tts\Engines\ElevenLabsEngine;
use App\Services\Tts\Engines\NullTtsEngine;
use Tests\TestCase;

class TtsEngineBindingTest extends TestCase
{
    public function test_resolves_null_engine_when_driver_is_null(): void
    {
        config(['tts.driver' => 'null']);

        $engine = $this->app->make(TtsEngine::class);

        $this->assertInstanceOf(NullTtsEngine::class, $engine);
        $this->assertSame('null', $engine->getProviderKey());
        $this->assertFalse($engine->isConfigured());
    }

    public function test_resolves_elevenlabs_engine_when_driver_is_elevenlabs(): void
    {
        config(['tts.driver' => 'elevenlabs']);

        $engine = $this->app->make(TtsEngine::class);

        $this->assertInstanceOf(ElevenLabsEngine::class, $engine);
        $this->assertSame('elevenlabs', $engine->getProviderKey());
    }
}
