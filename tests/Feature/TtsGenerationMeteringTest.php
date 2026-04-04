<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteTtsUsageEvent;
use App\Models\Token;
use App\Models\TtsAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per tts_robustness roadmap: TtsGenerationMeter records on every successful synthesize.
 * Preview and job both increment; display retrieval (token/station endpoints) does not.
 */
class TtsGenerationMeteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_stream_records_usage_event_when_synthesis_succeeds(): void
    {
        Storage::fake('local');
        config(['tts.driver' => 'elevenlabs']);

        $site = Site::query()->create([
            'name' => 'Test Site',
            'slug' => 'test-site-'.Str::lower(Str::random(6)),
            'api_key_hash' => '',
            'settings' => [],
            'edge_settings' => [],
            'is_default' => true,
        ]);

        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        TtsAccount::create([
            'label' => 'Test',
            'provider' => 'elevenlabs',
            'api_key' => Crypt::encryptString('sk_test_key'),
            'model_id' => 'eleven_multilingual_v2',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.elevenlabs.io/v1/text-to-speech/21m00Tcm4TlvDq8ikWAM' => Http::response('fake-mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $this->actingAs($admin);
        $response = $this->get('/api/public/tts?text=Hello+world&voice=21m00Tcm4TlvDq8ikWAM&rate=0.84');

        $response->assertOk();
        $this->assertDatabaseCount('site_tts_usage_events', 1);
        $event = SiteTtsUsageEvent::first();
        $this->assertSame((string) $site->id, (string) $event->site_id);
        $this->assertSame('elevenlabs', $event->provider);
        $this->assertSame(11, $event->chars_used);
        $this->assertSame('preview', $event->source);
    }

    public function test_budget_guard_blocks_generation_when_limit_exceeded(): void
    {
        Storage::fake('local');
        config(['tts.driver' => 'elevenlabs']);

        $site = Site::query()->create([
            'name' => 'Budget Site',
            'slug' => 'budget-site-'.Str::lower(Str::random(6)),
            'api_key_hash' => '',
            'settings' => [
                'tts_budget' => [
                    'enabled' => true,
                    'mode' => 'chars',
                    'period' => 'monthly',
                    'limit' => 5,
                    'block_on_limit' => true,
                ],
            ],
            'edge_settings' => [],
            'is_default' => false,
        ]);

        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        TtsAccount::create([
            'label' => 'Test',
            'provider' => 'elevenlabs',
            'api_key' => Crypt::encryptString('sk_test_key'),
            'model_id' => 'eleven_multilingual_v2',
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.elevenlabs.io/v1/text-to-speech/21m00Tcm4TlvDq8ikWAM' => Http::response('fake-mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $this->actingAs($admin);

        $first = $this->get('/api/public/tts?text=Hi&voice=21m00Tcm4TlvDq8ikWAM&rate=0.84');
        $first->assertOk();

        $second = $this->get('/api/public/tts?text=Hello+world&voice=21m00Tcm4TlvDq8ikWAM&rate=0.84');
        $second->assertStatus(503);
    }

    public function test_token_endpoint_does_not_record_usage_when_serving_pregenerated(): void
    {
        Storage::fake('local');
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'available';
        $token->save();
        $path = 'tts/tokens/'.$token->id.'.mp3';
        Storage::put($path, 'fake-mp3');
        $token->update(['tts_audio_path' => $path]);

        $response = $this->get("/api/public/tts/token/{$token->id}");

        $response->assertOk();
        $this->assertDatabaseCount('site_tts_usage_events', 0);
    }
}
