<?php

namespace Tests\Feature\Api;

use App\Models\Token;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /api/public/tts — public, rate-limited. Returns 503 when TTS disabled or generation fails.
 * GET /api/public/tts/token/{token} — stream pre-generated token TTS or 404.
 */
class TtsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createToken(?string $ttsAudioPath = null): Token
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'available';
        $token->tts_audio_path = $ttsAudioPath;
        $token->save();

        return $token;
    }

    public function test_tts_token_returns_404_when_token_has_no_tts_audio(): void
    {
        $token = $this->createToken(null);

        $response = $this->get("/api/public/tts/token/{$token->id}");

        $response->assertStatus(404);
    }

    public function test_tts_token_returns_404_when_file_missing(): void
    {
        $token = $this->createToken('tts/tokens/999.mp3');

        $response = $this->get("/api/public/tts/token/{$token->id}");

        $response->assertStatus(404);
    }

    public function test_tts_token_returns_200_and_audio_when_file_exists(): void
    {
        Storage::fake('local');
        $token = $this->createToken(null);
        $path = 'tts/tokens/'.$token->id.'.mp3';
        Storage::put($path, 'fake-mp3-content');
        $token->update(['tts_audio_path' => $path]);

        $response = $this->get("/api/public/tts/token/{$token->id}");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'audio/mpeg');
        $response->assertHeader('Cache-Control');
    }

    public function test_tts_requires_text_param(): void
    {
        $response = $this->getJson('/api/public/tts');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['text']);
    }

    public function test_tts_returns_503_when_disabled(): void
    {
        $this->app['config']->set('tts.driver', 'null');

        $response = $this->get('/api/public/tts?text=Calling+A+3');

        $response->assertStatus(503);
    }

    public function test_tts_voices_returns_list(): void
    {
        $response = $this->getJson('/api/public/tts/voices');

        $response->assertOk();
        $response->assertJsonStructure(['voices' => [['id', 'name', 'lang']]]);
    }
}
