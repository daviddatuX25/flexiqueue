<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Token;
use App\Models\User;
use App\Jobs\GenerateTokenTtsJob;
use App\Models\TokenTtsSetting;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per 08-API-SPEC-PHASE1 §5.5: GET /api/admin/tokens, POST /api/admin/tokens/batch, PUT /api/admin/tokens/{id}.
 * All require role:admin.
 */
class TokenControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    private function createToken(string $physicalId = 'A1', string $status = 'available'): Token
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).$physicalId);
        $token->physical_id = $physicalId;
        $token->status = $status;
        $token->save();

        return $token;
    }

    public function test_index_returns_tokens_list(): void
    {
        $this->createToken('A1', 'available');
        $this->createToken('A2', 'available');

        $response = $this->actingAs($this->admin)->getJson('/api/admin/tokens');

        $response->assertStatus(200);
        $response->assertJsonPath('tokens.0.physical_id', 'A1');
        $response->assertJsonPath('tokens.0.status', 'available');
        $response->assertJsonPath('tokens.0.pronounce_as', 'letters');
        $response->assertJsonPath('tokens.0.tts_status', null);
        $response->assertJsonPath('tokens.0.has_tts_audio', false);
        $response->assertJsonCount(2, 'tokens');
    }

    public function test_index_can_filter_by_status(): void
    {
        $t1 = $this->createToken('A1', 'available');
        $t2 = $this->createToken('A2', 'available');
        $this->createToken('A3', 'available');
        // Create session to make A2 in_use
        $user = User::factory()->create();
        $program = \App\Models\Program::create(['name' => 'P', 'description' => null, 'is_active' => true, 'created_by' => $user->id]);
        $station = \App\Models\Station::create(['program_id' => $program->id, 'name' => 'S', 'capacity' => 1, 'is_active' => true]);
        $track = \App\Models\ServiceTrack::create(['program_id' => $program->id, 'name' => 'T', 'is_default' => true]);
        \App\Models\TrackStep::create(['track_id' => $track->id, 'station_id' => $station->id, 'step_order' => 1, 'is_required' => true]);
        $session = \App\Models\Session::create([
            'token_id' => $t2->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A2',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);
        $t2->update(['status' => 'in_use', 'current_session_id' => $session->id]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/tokens?status=available');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'tokens');
        $ids = collect($response->json('tokens'))->pluck('physical_id')->all();
        $this->assertContains('A1', $ids);
        $this->assertContains('A3', $ids);
        $this->assertNotContains('A2', $ids);
    }

    public function test_index_can_search_by_physical_id(): void
    {
        $this->createToken('A1', 'available');
        $this->createToken('B10', 'available');
        $this->createToken('A10', 'available');

        $response = $this->actingAs($this->admin)->getJson('/api/admin/tokens?search=A1');

        $response->assertStatus(200);
        $tokens = $response->json('tokens');
        $this->assertCount(2, $tokens); // A1 and A10
        $ids = collect($tokens)->pluck('physical_id')->all();
        $this->assertContains('A1', $ids);
        $this->assertContains('A10', $ids);
        $this->assertNotContains('B10', $ids);
    }

    public function test_batch_create_returns_201_with_created_tokens(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/tokens/batch', [
            'prefix' => 'A',
            'count' => 3,
            'start_number' => 1,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('created', 3);
        $response->assertJsonCount(3, 'tokens');
        $response->assertJsonPath('tokens.0.physical_id', 'A1');
        $response->assertJsonPath('tokens.0.status', 'available');
        $response->assertJsonPath('tokens.1.physical_id', 'A2');
        $response->assertJsonPath('tokens.2.physical_id', 'A3');
        $response->assertJsonPath('tokens.0.tts_status', null);
        $response->assertJsonPath('tokens.0.has_tts_audio', false);
        $this->assertDatabaseCount('tokens', 3);
        $response->assertJsonPath('tokens.0.pronounce_as', 'letters');
    }

    public function test_batch_create_marks_tokens_for_tts_and_dispatches_job_when_server_tts_enabled(): void
    {
        $this->app['config']->set('tts.driver', 'elevenlabs');
        $this->app['config']->set('tts.elevenlabs.api_key', 'fake-key');

        Bus::fake();

        $response = $this->actingAs($this->admin)->postJson('/api/admin/tokens/batch', [
            'prefix' => 'A',
            'count' => 2,
            'start_number' => 1,
        ]);

        $response->assertStatus(201);
        $ids = collect($response->json('tokens'))->pluck('id')->all();

        foreach ($ids as $id) {
            $this->assertDatabaseHas('tokens', [
                'id' => $id,
                'tts_pre_generate_enabled' => true,
                'tts_status' => 'generating',
            ]);
        }

        Bus::assertDispatched(GenerateTokenTtsJob::class, function (GenerateTokenTtsJob $job) use ($ids) {
            sort($ids);
            $jobIds = $job->tokenIds;
            sort($jobIds);

            return $jobIds === $ids;
        });
    }

    public function test_batch_create_with_pronounce_as_word_persists_and_returns(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/tokens/batch', [
            'prefix' => 'B',
            'count' => 2,
            'start_number' => 1,
            'pronounce_as' => 'word',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('created', 2);
        $response->assertJsonPath('tokens.0.physical_id', 'B1');
        $response->assertJsonPath('tokens.0.pronounce_as', 'word');
        $response->assertJsonPath('tokens.1.pronounce_as', 'word');
        $this->assertDatabaseHas('tokens', ['physical_id' => 'B1', 'pronounce_as' => 'word']);
    }

    public function test_batch_create_rejects_invalid_pronounce_as(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/tokens/batch', [
            'prefix' => 'A',
            'count' => 1,
            'start_number' => 1,
            'pronounce_as' => 'invalid',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['pronounce_as']);
    }

    public function test_batch_create_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/tokens/batch', [
            'prefix' => '',
            'count' => 0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['prefix', 'count', 'start_number']);
    }

    public function test_update_token_status_to_available_returns_200(): void
    {
        $token = $this->createToken('A1', 'available');

        $response = $this->actingAs($this->admin)->putJson("/api/admin/tokens/{$token->id}", [
            'status' => 'available',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('token.status', 'available');
    }

    public function test_destroy_soft_deletes_available_token_returns_200(): void
    {
        $token = $this->createToken('A1', 'available');

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/tokens/{$token->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('deleted', true);
        $this->assertSoftDeleted('tokens', ['id' => $token->id]);
    }

    public function test_destroy_token_in_use_returns_409(): void
    {
        $token = $this->createToken('A1', 'available');
        $user = User::factory()->create();
        $program = \App\Models\Program::create(['name' => 'P', 'description' => null, 'is_active' => true, 'created_by' => $user->id]);
        $station = \App\Models\Station::create(['program_id' => $program->id, 'name' => 'S', 'capacity' => 1, 'is_active' => true]);
        $track = \App\Models\ServiceTrack::create(['program_id' => $program->id, 'name' => 'T', 'is_default' => true]);
        \App\Models\TrackStep::create(['track_id' => $track->id, 'station_id' => $station->id, 'step_order' => 1, 'is_required' => true]);
        $session = \App\Models\Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);
        $token->update(['status' => 'in_use', 'current_session_id' => $session->id]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/tokens/{$token->id}");

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Cannot delete token in use.');
    }

    public function test_batch_delete_soft_deletes_tokens_returns_200(): void
    {
        $t1 = $this->createToken('A1', 'available');
        $t2 = $this->createToken('A2', 'available');

        $response = $this->actingAs($this->admin)->postJson('/api/admin/tokens/batch-delete', [
            'ids' => [$t1->id, $t2->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('deleted', 2);
        $this->assertSoftDeleted('tokens', ['id' => $t1->id]);
        $this->assertSoftDeleted('tokens', ['id' => $t2->id]);
    }

    public function test_token_delete_cleans_up_tts_files(): void
    {
        $token = $this->createToken('A1', 'available');
        \Illuminate\Support\Facades\Storage::fake('local');

        // Simulate legacy single-file and per-language audio paths.
        $token->tts_audio_path = 'tts/tokens/'.$token->id.'.mp3';
        $token->tts_settings = [
            'languages' => [
                'en' => ['audio_path' => 'tts/tokens/'.$token->id.'/en.mp3', 'status' => 'ready'],
            ],
        ];
        $token->save();

        \Illuminate\Support\Facades\Storage::put($token->tts_audio_path, 'audio');
        \Illuminate\Support\Facades\Storage::put('tts/tokens/'.$token->id.'/en.mp3', 'audio');

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/tokens/{$token->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('tokens', ['id' => $token->id]);
        \Illuminate\Support\Facades\Storage::assertMissing($token->tts_audio_path);
        \Illuminate\Support\Facades\Storage::assertMissing('tts/tokens/'.$token->id.'/en.mp3');
    }

    public function test_batch_delete_with_in_use_returns_409(): void
    {
        $t1 = $this->createToken('A1', 'available');
        $t2 = $this->createToken('A2', 'available');
        $user = User::factory()->create();
        $program = \App\Models\Program::create(['name' => 'P', 'description' => null, 'is_active' => true, 'created_by' => $user->id]);
        $station = \App\Models\Station::create(['program_id' => $program->id, 'name' => 'S', 'capacity' => 1, 'is_active' => true]);
        $track = \App\Models\ServiceTrack::create(['program_id' => $program->id, 'name' => 'T', 'is_default' => true]);
        \App\Models\TrackStep::create(['track_id' => $track->id, 'station_id' => $station->id, 'step_order' => 1, 'is_required' => true]);
        $session = \App\Models\Session::create([
            'token_id' => $t2->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A2',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);
        $t2->update(['status' => 'in_use', 'current_session_id' => $session->id]);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/tokens/batch-delete', [
            'ids' => [$t1->id, $t2->id],
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Cannot delete token(s) in use.');
        $response->assertJsonPath('in_use_ids.0', $t2->id);
    }

    public function test_update_token_status_to_deactivated_returns_200(): void
    {
        $token = $this->createToken('A1', 'available');

        $response = $this->actingAs($this->admin)->putJson("/api/admin/tokens/{$token->id}", [
            'status' => 'deactivated',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('token.status', 'deactivated');
        $this->assertDatabaseHas('tokens', ['id' => $token->id, 'status' => 'deactivated']);
    }

    public function test_update_deactivated_to_available_returns_200(): void
    {
        $token = $this->createToken('A1', 'deactivated');

        $response = $this->actingAs($this->admin)->putJson("/api/admin/tokens/{$token->id}", [
            'status' => 'available',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('token.status', 'available');
    }

    public function test_update_in_use_to_deactivated_returns_409(): void
    {
        $token = $this->createToken('A1', 'available');
        $user = User::factory()->create();
        $program = \App\Models\Program::create(['name' => 'P', 'description' => null, 'is_active' => true, 'created_by' => $user->id]);
        $station = \App\Models\Station::create(['program_id' => $program->id, 'name' => 'S', 'capacity' => 1, 'is_active' => true]);
        $track = \App\Models\ServiceTrack::create(['program_id' => $program->id, 'name' => 'T', 'is_default' => true]);
        \App\Models\TrackStep::create(['track_id' => $track->id, 'station_id' => $station->id, 'step_order' => 1, 'is_required' => true]);
        $session = \App\Models\Session::create([
            'token_id' => $token->id,
            'program_id' => $program->id,
            'track_id' => $track->id,
            'alias' => 'A1',
            'current_station_id' => $station->id,
            'current_step_order' => 1,
            'status' => 'serving',
        ]);
        $token->update(['status' => 'in_use', 'current_session_id' => $session->id]);

        $response = $this->actingAs($this->admin)->putJson("/api/admin/tokens/{$token->id}", [
            'status' => 'deactivated',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Cannot deactivate token in use. Mark it available first.');
    }

    public function test_update_token_status_validates_allowed_values(): void
    {
        $token = $this->createToken('A1', 'available');

        $response = $this->actingAs($this->admin)->putJson("/api/admin/tokens/{$token->id}", [
            'status' => 'invalid',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('status');
    }

    public function test_non_admin_cannot_access_index(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $response = $this->actingAs($staff)->getJson('/api/admin/tokens');
        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_batch_create(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $response = $this->actingAs($staff)->postJson('/api/admin/tokens/batch', [
            'prefix' => 'A',
            'count' => 5,
            'start_number' => 1,
        ]);
        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_update_token(): void
    {
        $token = $this->createToken('A1', 'available');
        $staff = User::factory()->create(['role' => 'staff']);
        $response = $this->actingAs($staff)->putJson("/api/admin/tokens/{$token->id}", ['status' => 'available']);
        $response->assertStatus(403);
    }

    /**
     * When queue worker appears idle and sync fallback is disabled, batch create returns 503.
     */
    public function test_batch_create_returns_503_when_queue_worker_idle_and_sync_fallback_disabled(): void
    {
        $this->app['config']->set('queue.default', 'database');
        $this->app['config']->set('tts.allow_sync_when_queue_unavailable', false);
        $this->app['config']->set('tts.driver', 'elevenlabs');
        $this->app['config']->set('tts.elevenlabs.api_key', 'fake-key');
        TokenTtsSetting::instance()->update(['voice_id' => 'voice-1', 'rate' => 1.0]);

        $cutoff = now()->subMinutes(3)->timestamp;
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\Jobs\SomeJob', 'job' => 'Illuminate\Queue\CallQueuedHandler@call']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $cutoff,
            'created_at' => $cutoff,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/tokens/batch', [
            'prefix' => 'A',
            'count' => 2,
            'start_number' => 1,
        ]);

        $response->assertStatus(503);
        $response->assertJsonPath('message', 'Queue worker is not running. Start it with: php artisan queue:work');
    }

    /**
     * When queue worker appears idle and sync fallback is enabled, batch create succeeds (TTS runs sync).
     */
    public function test_batch_create_succeeds_with_sync_fallback_when_queue_worker_idle(): void
    {
        $this->app['config']->set('queue.default', 'database');
        $this->app['config']->set('tts.allow_sync_when_queue_unavailable', true);
        $this->app['config']->set('tts.driver', 'elevenlabs');
        $this->app['config']->set('tts.elevenlabs.api_key', 'fake-key');
        TokenTtsSetting::instance()->update(['voice_id' => 'voice-1', 'rate' => 1.0]);

        $ttsService = $this->createMock(\App\Services\TtsService::class);
        $ttsService->method('isEnabled')->willReturn(true);
        $ttsService->method('storeSegment')->willReturn('tts/tokens/1/en.mp3');
        $ttsService->method('storeTokenTts')->willReturn('tts/tokens/1.mp3');
        $this->app->instance(\App\Services\TtsService::class, $ttsService);

        $cutoff = now()->subMinutes(3)->timestamp;
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\Jobs\SomeJob', 'job' => 'Illuminate\Queue\CallQueuedHandler@call']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $cutoff,
            'created_at' => $cutoff,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/tokens/batch', [
            'prefix' => 'A',
            'count' => 2,
            'start_number' => 1,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('created', 2);
    }

    /**
     * When queue worker idle, sync fallback enabled, and batch size exceeds max_sync_tokens, returns 503.
     */
    public function test_batch_create_returns_503_when_sync_fallback_but_batch_exceeds_max_sync_tokens(): void
    {
        $this->app['config']->set('queue.default', 'database');
        $this->app['config']->set('tts.allow_sync_when_queue_unavailable', true);
        $this->app['config']->set('tts.max_sync_tokens', 5);
        $this->app['config']->set('tts.driver', 'elevenlabs');
        $this->app['config']->set('tts.elevenlabs.api_key', 'fake-key');
        TokenTtsSetting::instance()->update(['voice_id' => 'voice-1', 'rate' => 1.0]);

        $cutoff = now()->subMinutes(3)->timestamp;
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\Jobs\SomeJob', 'job' => 'Illuminate\Queue\CallQueuedHandler@call']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $cutoff,
            'created_at' => $cutoff,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/tokens/batch', [
            'prefix' => 'A',
            'count' => 10,
            'start_number' => 1,
        ]);

        $response->assertStatus(503);
        $this->assertStringContainsString('Queue worker is required for large batches', $response->json('message'));
    }
}
