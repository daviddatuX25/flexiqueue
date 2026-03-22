<?php

namespace Tests\Feature\Api;

use App\Models\Program;
use App\Models\Site;
use App\Models\Station;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /api/public/tts — authenticated admin/super_admin, rate-limited. Returns 503 when TTS disabled or generation fails.
 * Used by admin preview only; display playback must NOT call this for fallback (per ElevenLabs generation-only policy).
 * GET /api/public/tts/token/{token} — stream pre-generated token TTS or 404.
 * GET /api/public/tts/station/{id}/{lang} — stream pre-generated station TTS or 404.
 */
class TtsControllerTest extends TestCase
{
    private function createAdminForSite(Site $site): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'site_id' => $site->id,
        ]);
    }

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

    private function createStationWithTtsPath(?string $audioPath): Station
    {
        $site = Site::query()->create([
            'name' => 'Test Site',
            'slug' => 'test-site-'.Str::lower(Str::random(6)),
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
            'is_default' => true,
        ]);
        $user = User::factory()->create();
        $program = Program::create([
            'site_id' => $site->id,
            'name' => 'Program '.Str::random(6),
            'description' => null,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        return Station::query()->create([
            'program_id' => $program->id,
            'name' => 'Window 1',
            'capacity' => 1,
            'is_active' => true,
            'settings' => [
                'tts' => [
                    'languages' => [
                        'en' => ['audio_path' => $audioPath],
                    ],
                ],
            ],
        ]);
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

    public function test_tts_token_uses_revision_ready_en_audio_path_when_legacy_tts_audio_path_missing(): void
    {
        Storage::fake('local');
        $token = $this->createToken(null);
        $path = 'tts/tokens/'.$token->id.'/en/r2-calling-a1-abcd1234.mp3';
        Storage::put($path, 'fake-mp3-content');
        $token->update([
            'tts_audio_path' => null,
            'tts_settings' => [
                'languages' => [
                    'en' => [
                        'audio_path' => $path,
                        'status' => 'ready',
                        'asset_meta' => [
                            'canonical_key' => 'token|'.$token->id.'|en|calling a1|voice-1|0.840|r:2',
                            'revision' => 2,
                            'hash' => 'abcd1234',
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->get("/api/public/tts/token/{$token->id}");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'audio/mpeg');
    }

    public function test_tts_requires_text_param(): void
    {
        $site = Site::query()->create([
            'name' => 'Auth Site',
            'slug' => 'auth-site-'.Str::lower(Str::random(6)),
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
            'is_default' => true,
        ]);
        $admin = $this->createAdminForSite($site);

        $response = $this->actingAs($admin)->getJson('/api/public/tts');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['text']);
    }

    public function test_tts_requires_authenticated_user(): void
    {
        $response = $this->getJson('/api/public/tts?text=Calling+A+3');

        $response->assertStatus(401);
    }

    public function test_tts_requires_admin_or_super_admin_role(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($staff)->get('/api/public/tts?text=Calling+A+3');

        $response->assertForbidden();
    }

    public function test_tts_returns_503_when_disabled(): void
    {
        $this->app['config']->set('tts.driver', 'null');
        $site = Site::query()->create([
            'name' => 'Disabled Site',
            'slug' => 'disabled-site-'.Str::lower(Str::random(6)),
            'api_key_hash' => Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
            'is_default' => true,
        ]);
        $admin = $this->createAdminForSite($site);

        $response = $this->actingAs($admin)->get('/api/public/tts?text=Calling+A+3');

        $response->assertStatus(503);
    }

    public function test_tts_voices_returns_list(): void
    {
        $this->app['config']->set('tts.driver', 'elevenlabs');

        $response = $this->getJson('/api/public/tts/voices');

        $response->assertOk();
        $response->assertJsonStructure(['voices' => [['id', 'name', 'lang']]]);
    }

    public function test_tts_station_returns_200_and_audio_when_file_exists(): void
    {
        Storage::fake('local');
        $path = 'tts/stations/1-en.mp3';
        Storage::put($path, 'fake-mp3-content');
        $station = $this->createStationWithTtsPath($path);

        $response = $this->get("/api/public/tts/station/{$station->id}/en");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'audio/mpeg');
        $response->assertHeader('Cache-Control');
    }

    public function test_tts_station_returns_404_when_path_missing(): void
    {
        $station = $this->createStationWithTtsPath(null);

        $response = $this->get("/api/public/tts/station/{$station->id}/en");

        $response->assertStatus(404);
    }

    public function test_tts_station_returns_404_when_file_missing(): void
    {
        $station = $this->createStationWithTtsPath('tts/stations/missing.mp3');

        $response = $this->get("/api/public/tts/station/{$station->id}/en");

        $response->assertStatus(404);
    }

    public function test_tts_station_returns_404_for_invalid_language(): void
    {
        $station = $this->createStationWithTtsPath('tts/stations/1-en.mp3');

        $response = $this->get("/api/public/tts/station/{$station->id}/xx");

        $response->assertStatus(404);
    }

    /**
     * Per ElevenLabs generation-only policy: token and station endpoints serve pre-generated assets only.
     * When they return 404, display uses Web Speech (no call to GET /api/public/tts?text=).
     * Regression: retrieval endpoints never trigger on-demand generation.
     */
    public function test_token_and_station_endpoints_serve_pregenerated_only_when_missing_return_404(): void
    {
        $token = $this->createToken(null);
        $station = $this->createStationWithTtsPath(null);

        $tokenResponse = $this->get("/api/public/tts/token/{$token->id}");
        $stationResponse = $this->get("/api/public/tts/station/{$station->id}/en");

        $tokenResponse->assertStatus(404);
        $stationResponse->assertStatus(404);
    }
}
