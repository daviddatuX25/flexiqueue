<?php

namespace Tests\Unit\Services;

use App\Exceptions\TtsQuotaExceededException;
use App\Services\ElevenLabsClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ElevenLabsClientTest extends TestCase
{
    public function test_generate_speech_success_returns_audio_body(): void
    {
        Http::fake([
            'https://api.elevenlabs.io/v1/text-to-speech/voice1' => Http::response('mp3bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $client = new ElevenLabsClient('sk_test_key');
        $result = $client->generateSpeech('hi', 'voice1', 'model1');

        $this->assertSame('mp3bytes', $result);
    }

    public function test_generate_speech_http_error_returns_null(): void
    {
        Http::fake([
            'https://api.elevenlabs.io/v1/text-to-speech/voice1' => Http::response(['error' => 'Server error'], 500),
        ]);

        $client = new ElevenLabsClient('sk_test_key');
        $result = $client->generateSpeech('hi', 'voice1', 'model1');

        $this->assertNull($result);
    }

    public function test_generate_speech_quota_exceeded_throws(): void
    {
        Http::fake([
            'https://api.elevenlabs.io/v1/text-to-speech/voice1' => Http::response([
                'detail' => [
                    'status' => 'quota_exceeded',
                    'message' => 'Character quota exceeded',
                ],
            ], 429),
        ]);

        $client = new ElevenLabsClient('sk_test_key');

        $this->expectException(TtsQuotaExceededException::class);
        $this->expectExceptionMessage('Character quota exceeded');

        $client->generateSpeech('hi', 'voice1', 'model1');
    }

    public function test_generate_speech_sends_voice_settings_when_provided(): void
    {
        $requestBody = null;
        Http::fake([
            'https://api.elevenlabs.io/v1/text-to-speech/v' => function ($request) use (&$requestBody) {
                $requestBody = $request->data();

                return Http::response('ok', 200);
            },
        ]);

        $client = new ElevenLabsClient('sk_test_key');
        $client->generateSpeech('hi', 'v', 'm', ['stability' => 0.5, 'similarity_boost' => 0.75]);

        $this->assertIsArray($requestBody);
        $this->assertSame('hi', $requestBody['text']);
        $this->assertSame('m', $requestBody['model_id']);
        $this->assertArrayHasKey('voice_settings', $requestBody);
        $this->assertSame(0.5, $requestBody['voice_settings']['stability']);
        $this->assertSame(0.75, $requestBody['voice_settings']['similarity_boost']);
    }
}
