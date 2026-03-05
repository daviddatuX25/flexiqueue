<?php

namespace Tests\Feature\Api\Admin;

use App\Models\TokenTtsSetting;
use App\Models\User;
use App\Models\Token;
use App\Jobs\GenerateTokenTtsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TokenTtsSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_default_settings(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->getJson('/api/admin/token-tts-settings');

        $response->assertStatus(200);
        $response->assertJsonStructure(['token_tts_settings' => ['voice_id', 'rate']]);
        $data = $response->json('token_tts_settings');
        $this->assertArrayHasKey('rate', $data);
    }

    public function test_update_saves_settings(): void
    {
        $admin = User::factory()->admin()->create();
        TokenTtsSetting::instance();

        $token = csrf_token();

        $response = $this->actingAs($admin)
            ->withHeader('X-CSRF-TOKEN', $token)
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
        $admin = User::factory()->admin()->create();
        $settings = TokenTtsSetting::instance();
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
        Token::factory()->create([
            'tts_pre_generate_enabled' => true,
            'tts_status' => 'pre_generated',
        ]);
        Token::factory()->create([
            'tts_pre_generate_enabled' => true,
            'tts_status' => 'pre_generated',
        ]);

        $response2 = $this->actingAs($admin)->putJson('/api/admin/token-tts-settings', [
            'voice_id' => 'voice-3',
            'rate' => 1.3,
        ]);

        $response2->assertStatus(200);
        $response2->assertJsonPath('requires_regeneration', true);
    }

    public function test_regenerate_tts_endpoint_queues_tokens_when_enabled(): void
    {
        $admin = User::factory()->admin()->create();
        $this->app['config']->set('tts.driver', 'elevenlabs');
        $this->app['config']->set('tts.elevenlabs.api_key', 'fake-key');

        Bus::fake();

        $t1 = Token::factory()->create([
            'tts_pre_generate_enabled' => true,
            'tts_status' => 'pre_generated',
        ]);
        $t2 = Token::factory()->create([
            'tts_pre_generate_enabled' => true,
            'tts_status' => 'pre_generated',
        ]);

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

    public function test_regenerate_tts_endpoint_returns_503_when_server_tts_disabled(): void
    {
        $admin = User::factory()->admin()->create();
        $this->app['config']->set('tts.driver', 'null');

        $response = $this->actingAs($admin)->postJson('/api/admin/tokens/regenerate-tts');

        $response->assertStatus(503);
        $response->assertJsonPath('queued', 0);
    }

    public function test_non_admin_cannot_access(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)->getJson('/api/admin/token-tts-settings')->assertStatus(403);
        $token = csrf_token();
        $this->actingAs($staff)
            ->withHeader('X-CSRF-TOKEN', $token)
            ->putJson('/api/admin/token-tts-settings', [])
            ->assertStatus(403);
    }
}

