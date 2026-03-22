<?php

namespace Tests\Feature\Api\Admin;

use App\Events\TokenTtsStatusUpdated;
use App\Jobs\GenerateTokenTtsJob;
use App\Models\Site;
use App\Models\Token;
use App\Models\User;
use App\Repositories\TokenTtsSettingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\TestCase;

class TokenTtsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private ?Site $site = null;

    private function site(): Site
    {
        if ($this->site === null) {
            $this->site = Site::create([
                'name' => 'Default Site',
                'slug' => 'default',
                'api_key_hash' => Hash::make(Str::random(40)),
                'settings' => [],
                'edge_settings' => [],
            ]);
        }

        return $this->site;
    }

    private function createToken(bool $ttsPreGenerateEnabled = false, ?string $ttsStatus = null): Token
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'T'.Str::random(8));
        $token->physical_id = 'T'.Str::random(4);
        $token->site_id = $this->site()->id;
        $token->status = 'available';
        $token->tts_pre_generate_enabled = $ttsPreGenerateEnabled;
        $token->tts_status = $ttsStatus;
        $token->save();

        return $token;
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['site_id' => $this->site()->id]);
    }

    public function test_show_returns_default_settings(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->getJson('/api/admin/token-tts-settings');

        $response->assertStatus(200);
        $response->assertJsonStructure(['token_tts_settings' => ['voice_id', 'rate', 'playback']]);
        $data = $response->json('token_tts_settings');
        $this->assertArrayHasKey('rate', $data);
        $this->assertTrue($data['playback']['prefer_generated_audio']);
        $this->assertTrue($data['playback']['segment_2_enabled']);
    }

    public function test_update_saves_playback_flags(): void
    {
        $admin = $this->admin();
        $this->app->make(TokenTtsSettingRepository::class)->getInstance();

        $response = $this->actingAs($admin)->putJson('/api/admin/token-tts-settings', [
            'playback' => [
                'prefer_generated_audio' => false,
                'allow_custom_pronunciation' => false,
                'segment_2_enabled' => false,
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('token_tts_settings.playback.prefer_generated_audio', false);
        $response->assertJsonPath('token_tts_settings.playback.allow_custom_pronunciation', false);
        $response->assertJsonPath('token_tts_settings.playback.segment_2_enabled', false);
    }

    public function test_preview_text_segment1_and_segment2(): void
    {
        $admin = $this->admin();

        $r1 = $this->actingAs($admin)->getJson('/api/admin/tts/preview-text?segment=1&lang=en&alias=A1&pronounce_as=letters');
        $r1->assertStatus(200);
        $this->assertStringContainsString('Calling', $r1->json('text'));

        $r2 = $this->actingAs($admin)->getJson('/api/admin/tts/preview-text?segment=2&lang=en&connector_phrase=Go%20to&station_name=Win');
        $r2->assertStatus(200);
        $this->assertSame('Go to Win', $r2->json('text'));

        $rCustom = $this->actingAs($admin)->getJson('/api/admin/tts/preview-text?segment=1&lang=en&alias=A1&pronounce_as=custom&token_phrase=Counter');
        $rCustom->assertStatus(200);
        $this->assertStringContainsString('Counter', $rCustom->json('text'));

        $rWord = $this->actingAs($admin)->getJson('/api/admin/tts/preview-text?segment=1&lang=en&alias=A1&pronounce_as=word&token_phrase=Ignored');
        $rWord->assertStatus(200);
        $this->assertStringContainsString('A 1', $rWord->json('text'));
        $this->assertStringNotContainsString('Ignored', $rWord->json('text'));
    }

    public function test_update_merges_default_languages_preserving_unsent_phrase_fields(): void
    {
        $admin = $this->admin();
        $settings = $this->app->make(TokenTtsSettingRepository::class)->getInstance();
        $settings->update([
            'default_languages' => [
                'en' => [
                    'pre_phrase' => 'Hello',
                    'token_bridge_tail' => 'please go to',
                    'rate' => 0.9,
                    'voice_id' => 'v-en',
                ],
                'fil' => ['pre_phrase' => 'Fil pre'],
                'ilo' => [],
            ],
        ]);

        $response = $this->actingAs($admin)->putJson('/api/admin/token-tts-settings', [
            'languages' => [
                'en' => [
                    'rate' => 1.15,
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('token_tts_settings.languages.en.pre_phrase', 'Hello');
        $response->assertJsonPath('token_tts_settings.languages.en.token_bridge_tail', 'please go to');
        $response->assertJsonPath('token_tts_settings.languages.en.rate', 1.15);
        $response->assertJsonPath('token_tts_settings.languages.en.voice_id', 'v-en');
        $response->assertJsonPath('token_tts_settings.languages.fil.pre_phrase', 'Fil pre');

        $settings->refresh();
        $this->assertSame('Hello', $settings->getDefaultLanguages()['en']['pre_phrase'] ?? null);
    }

    public function test_update_saves_settings(): void
    {
        $admin = $this->admin();
        $this->app->make(TokenTtsSettingRepository::class)->getInstance();

        $this->actingAs($admin);
        Session::start();
        $token = Session::token();

        $response = $this->withHeader('X-CSRF-TOKEN', $token)
            ->putJson('/api/admin/token-tts-settings', [
                'voice_id' => 'custom-voice-id',
                'rate' => 1.25,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('token_tts_settings.voice_id', 'custom-voice-id');
        $response->assertJsonPath('token_tts_settings.rate', 1.25);
    }

    public function test_update_includes_requires_regeneration_flag_based_on_changes_and_tokens(): void
    {
        $admin = $this->admin();
        $settings = $this->app->make(TokenTtsSettingRepository::class)->getInstance();
        $settings->update([
            'voice_id' => 'voice-1',
            'rate' => 1.0,
        ]);

        // No tokens opted-in → no regeneration required even when values change.
        $response = $this->actingAs($admin)->putJson('/api/admin/token-tts-settings', [
            'voice_id' => 'voice-2',
            'rate' => 1.2,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('requires_regeneration', false);

        // Create tokens opted-in for pre-generation.
        $this->createToken(true, 'pre_generated');
        $this->createToken(true, 'pre_generated');

        $response2 = $this->actingAs($admin)->putJson('/api/admin/token-tts-settings', [
            'voice_id' => 'voice-3',
            'rate' => 1.3,
        ]);

        $response2->assertStatus(200);
        $response2->assertJsonPath('requires_regeneration', true);
    }

    public function test_regenerate_tts_endpoint_queues_tokens_when_enabled(): void
    {
        $admin = $this->admin();
        $this->app['config']->set('tts.driver', 'elevenlabs');
        $this->app['config']->set('tts.elevenlabs.api_key', 'fake-key');

        Bus::fake();

        $t1 = $this->createToken(true, 'pre_generated');
        $t2 = $this->createToken(true, 'pre_generated');

        $response = $this->actingAs($admin)->postJson('/api/admin/tokens/regenerate-tts');

        $response->assertStatus(200);
        $response->assertJsonPath('queued', 2);

        $this->assertDatabaseHas('tokens', [
            'id' => $t1->id,
            'tts_status' => 'generating',
        ]);
        $this->assertDatabaseHas('tokens', [
            'id' => $t2->id,
            'tts_status' => 'generating',
        ]);

        Bus::assertDispatched(GenerateTokenTtsJob::class, function (GenerateTokenTtsJob $job) use ($t1, $t2) {
            $expected = [$t1->id, $t2->id];
            sort($expected);
            $jobIds = $job->tokenIds;
            sort($jobIds);

            return $jobIds === $expected;
        });
    }

    public function test_regenerate_tts_with_token_ids_queues_only_those_tokens(): void
    {
        $admin = $this->admin();
        $this->app['config']->set('tts.driver', 'elevenlabs');
        $this->app['config']->set('tts.elevenlabs.api_key', 'fake-key');

        Bus::fake();

        $t1 = $this->createToken(false, null);
        $t2 = $this->createToken(false, null);
        $t3 = $this->createToken(true, 'pre_generated');

        $response = $this->actingAs($admin)->postJson('/api/admin/tokens/regenerate-tts', [
            'token_ids' => [$t1->id, $t2->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('queued', 2);

        $this->assertDatabaseHas('tokens', [
            'id' => $t1->id,
            'tts_status' => 'generating',
            'tts_pre_generate_enabled' => true,
        ]);
        $this->assertDatabaseHas('tokens', [
            'id' => $t2->id,
            'tts_status' => 'generating',
            'tts_pre_generate_enabled' => true,
        ]);
        $this->assertDatabaseHas('tokens', [
            'id' => $t3->id,
            'tts_status' => 'pre_generated',
        ]);

        Bus::assertDispatched(GenerateTokenTtsJob::class, function (GenerateTokenTtsJob $job) use ($t1, $t2) {
            $expected = [$t1->id, $t2->id];
            sort($expected);
            $jobIds = $job->tokenIds;
            sort($jobIds);

            return $jobIds === $expected;
        });
    }

    public function test_regenerate_tts_endpoint_returns_503_when_server_tts_disabled(): void
    {
        $admin = $this->admin();
        $this->app['config']->set('tts.driver', 'null');

        $response = $this->actingAs($admin)->postJson('/api/admin/tokens/regenerate-tts');

        $response->assertStatus(503);
        $response->assertJsonPath('queued', 0);
    }

    public function test_preview_token_spoken_part_returns_ilocano_phonetics_for_letter_a(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->getJson(
            '/api/admin/tts/preview-token-spoken-part?lang=ilo&alias=A1&pronounce_as=letters'
        );

        $response->assertStatus(200);
        $response->assertJsonStructure(['text']);
        $text = $response->json('text');
        $this->assertIsString($text);
        $this->assertStringContainsString('eyy', $text);
    }

    public function test_preview_token_spoken_part_supports_custom_token_phrase(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->getJson(
            '/api/admin/tts/preview-token-spoken-part?lang=en&alias=A1&pronounce_as=custom&token_phrase=Counter'
        );

        $response->assertStatus(200);
        $response->assertJsonStructure(['text']);
        $this->assertSame('Counter', $response->json('text'));
    }

    public function test_preview_token_spoken_part_word_mode_keeps_letter_runs_and_digits(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->getJson(
            '/api/admin/tts/preview-token-spoken-part?lang=en&alias=AAB3&pronounce_as=word'
        );

        $response->assertStatus(200);
        $response->assertJsonStructure(['text']);
        $this->assertSame('AAB 3', $response->json('text'));
    }

    public function test_sample_phrase_with_pre_phrase_returns_combined_text(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->getJson(
            '/api/admin/tts/sample-phrase?lang=en&pre_phrase=Calling&alias=A1&pronounce_as=letters'
        );

        $response->assertStatus(200);
        $text = $response->json('text');
        $this->assertStringStartsWith('Calling', $text);
    }

    public function test_super_admin_cannot_access_token_tts_settings(): void
    {
        $super = User::factory()->create(['role' => 'super_admin', 'site_id' => null]);

        $this->actingAs($super)->getJson('/api/admin/token-tts-settings')->assertStatus(403);
    }

    public function test_non_admin_cannot_access(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)->getJson('/api/admin/token-tts-settings')->assertStatus(403);
        $this->actingAs($staff)->getJson('/api/admin/tts/sample-phrase?lang=en')->assertStatus(403);
        $this->actingAs($staff)->getJson('/api/admin/tts/preview-text?segment=1&lang=en')->assertStatus(403);
        $this->actingAs($staff)->getJson('/api/admin/tts/preview-token-spoken-part?lang=en&alias=A1&pronounce_as=letters')->assertStatus(403);
        $token = csrf_token();
        $this->actingAs($staff)
            ->withHeader('X-CSRF-TOKEN', $token)
            ->putJson('/api/admin/token-tts-settings', [])
            ->assertStatus(403);
    }

    public function test_job_dispatches_token_tts_status_updated_per_token(): void
    {
        $this->app['config']->set('tts.driver', 'elevenlabs');
        $this->app['config']->set('tts.elevenlabs.api_key', 'fake-key');
        $this->app->make(TokenTtsSettingRepository::class)->getInstance();

        $t1 = $this->createToken(true, 'generating');
        $t2 = $this->createToken(true, 'generating');

        Event::fake([TokenTtsStatusUpdated::class]);

        $job = new GenerateTokenTtsJob([$t1->id, $t2->id]);
        $this->app->call([$job, 'handle']);

        Event::assertDispatched(TokenTtsStatusUpdated::class, 2);
        Event::assertDispatched(TokenTtsStatusUpdated::class, function ($event) use ($t1) {
            return $event->token->id === $t1->id;
        });
        Event::assertDispatched(TokenTtsStatusUpdated::class, function ($event) use ($t2) {
            return $event->token->id === $t2->id;
        });
    }
}
