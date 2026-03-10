<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BatchCreateTokenRequest;
use App\Http\Requests\BatchDeleteTokenRequest;
use App\Http\Requests\RegenerateTokenTtsRequest;
use App\Http\Requests\UpdateTokenRequest;
use App\Jobs\GenerateTokenTtsJob;
use App\Models\Token;
use App\Models\TokenTtsSetting;
use App\Services\TokenService;
use App\Services\TtsService;
use App\Support\QueueWorkerIdleCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Per 08-API-SPEC-PHASE1 §5.5: Token list, batch create, update status. Auth: role:admin.
 */
class TokenController extends Controller
{
    public function __construct(
        private TokenService $tokenService,
        private TtsService $ttsService
    ) {}

    /**
     * List tokens. Filterable: ?status=available|in_use, ?search= (physical_id substring).
     * Soft-deleted tokens excluded by default.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Token::query()->orderBy('physical_id');

        if ($request->has('status') && $request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->has('search') && $request->filled('search')) {
            $query->where('physical_id', 'like', '%'.$request->input('search').'%');
        }

        $tokens = $query->get()->map(fn (Token $t) => $this->tokenResource($t));

        return response()->json(['tokens' => $tokens]);
    }

    /**
     * Create token batch. Per spec: 201 with created count and tokens array.
     */
    public function batch(BatchCreateTokenRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $pronounceAs = $validated['pronounce_as'] ?? 'letters';

        $result = $this->tokenService->batchCreate(
            $validated['prefix'],
            $validated['count'],
            $validated['start_number'],
            $pronounceAs
        );

        $tokenIds = array_column($result['tokens'], 'id');

        // Persist per-batch TTS settings on tokens (three language rows).
        $ttsInput = $validated['tts'] ?? [];
        if ($tokenIds !== [] && is_array($ttsInput) && $ttsInput !== []) {
            $tokens = Token::query()
                ->whereIn('id', $tokenIds)
                ->get();

            foreach ($tokens as $token) {
                foreach (['en', 'fil', 'ilo'] as $lang) {
                    if (! isset($ttsInput[$lang]) || ! is_array($ttsInput[$lang])) {
                        continue;
                    }

                    $langInput = $ttsInput[$lang];
                    $config = $token->getTtsConfigFor($lang);

                    if (array_key_exists('voice_id', $langInput)) {
                        $config['voice_id'] = $langInput['voice_id'] !== '' ? $langInput['voice_id'] : null;
                    }

                    if (array_key_exists('rate', $langInput) && $langInput['rate'] !== null && $langInput['rate'] !== '') {
                        $config['rate'] = (float) $langInput['rate'];
                    }

                    if (array_key_exists('pre_phrase', $langInput)) {
                        $value = $langInput['pre_phrase'];
                        $config['pre_phrase'] = is_string($value) && trim($value) !== '' ? trim($value) : null;
                    }

                    $token->setTtsConfigFor($lang, $config);
                }

                $token->save();
            }
        }

        if ($tokenIds !== []) {
            // Always mark tokens as opted-in for pre-generation so future regeneration
            // can include them even if server TTS is currently disabled.
            Token::query()
                ->whereIn('id', $tokenIds)
                ->update([
                    'tts_pre_generate_enabled' => true,
                ]);

            if ($this->ttsService->isEnabled() && TokenTtsSetting::instance()->getEffectiveVoiceId() !== null) {
                $workerIdle = QueueWorkerIdleCheck::appearsIdle();
                if ($workerIdle && ! config('tts.allow_sync_when_queue_unavailable', false)) {
                    Token::query()
                        ->whereIn('id', $tokenIds)
                        ->update(['tts_status' => 'failed']);
                    return response()->json([
                        'message' => 'Queue worker is not running. Start it with: php artisan queue:work',
                    ], 503);
                }
                $maxSync = (int) config('tts.max_sync_tokens', 20);
                if ($workerIdle && config('tts.allow_sync_when_queue_unavailable', false) && count($tokenIds) > $maxSync) {
                    Token::query()
                        ->whereIn('id', $tokenIds)
                        ->update(['tts_status' => 'failed']);
                    return response()->json([
                        'message' => 'Queue worker is required for large batches. Start it with: php artisan queue:work, or reduce batch size to '.$maxSync.' or fewer.',
                    ], 503);
                }
                // Clear tokens stuck in "generating" (no worker, job died), but never touch tokens we're about to generate.
                Token::query()
                    ->where('tts_status', 'generating')
                    ->where('updated_at', '<', now()->subMinutes(5))
                    ->whereNotIn('id', $tokenIds)
                    ->update(['tts_status' => 'failed']);

                Token::query()
                    ->whereIn('id', $tokenIds)
                    ->update([
                        'tts_status' => 'generating',
                    ]);

                if ($workerIdle && config('tts.allow_sync_when_queue_unavailable', false)) {
                    GenerateTokenTtsJob::dispatchSync($tokenIds);
                } else {
                    GenerateTokenTtsJob::dispatch($tokenIds);
                }
            }
        }

        return response()->json($result, 201);
    }

    /**
     * Update token. Admin can set status (available/deactivated) and/or pronounce_as (letters/word).
     * Cannot deactivate a token that is in_use.
     */
    public function update(UpdateTokenRequest $request, Token $token): JsonResponse
    {
        $validated = $request->validated();
        $updates = [];

        if (array_key_exists('status', $validated)) {
            $status = $validated['status'];
            if ($status === 'deactivated' && $token->status === 'in_use') {
                return response()->json([
                    'message' => 'Cannot deactivate token in use. Mark it available first.',
                ], 409);
            }
            $updates['status'] = $status;
            $updates['current_session_id'] = $status !== 'in_use' ? null : $token->current_session_id;
        }

        if (array_key_exists('pronounce_as', $validated)) {
            $updates['pronounce_as'] = $validated['pronounce_as'];
        }

        if (! empty($updates)) {
            $token->update($updates);
            $token->refresh();
        }

        // Optional: update per-language TTS settings when provided.
        if (isset($validated['tts']) && is_array($validated['tts'])) {
            $ttsInput = $validated['tts'];

            foreach (['en', 'fil', 'ilo'] as $lang) {
                if (! isset($ttsInput[$lang]) || ! is_array($ttsInput[$lang])) {
                    continue;
                }

                $langInput = $ttsInput[$lang];
                $config = $token->getTtsConfigFor($lang);

                if (array_key_exists('voice_id', $langInput)) {
                    $config['voice_id'] = $langInput['voice_id'] !== '' ? $langInput['voice_id'] : null;
                }

                if (array_key_exists('rate', $langInput) && $langInput['rate'] !== null && $langInput['rate'] !== '') {
                    $config['rate'] = (float) $langInput['rate'];
                }

                if (array_key_exists('pre_phrase', $langInput)) {
                    $value = $langInput['pre_phrase'];
                    $config['pre_phrase'] = is_string($value) && trim($value) !== '' ? trim($value) : null;
                }

                $token->setTtsConfigFor($lang, $config);
            }

            $token->save();
            $token->refresh();
        }

        return response()->json(['token' => $this->tokenResource($token)]);
    }

    /**
     * Soft delete a single token. Fails with 409 if token is in_use.
     */
    public function destroy(Token $token): JsonResponse
    {
        if ($token->status === 'in_use') {
            return response()->json([
                'message' => 'Cannot delete token in use.',
            ], 409);
        }

        $token->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Soft delete multiple tokens. Fails with 409 if any are in_use.
     */
    public function batchDelete(BatchDeleteTokenRequest $request): JsonResponse
    {
        $ids = $request->validated('ids');
        $tokens = Token::query()->whereIn('id', $ids)->get();

        $inUse = $tokens->filter(fn (Token $t) => $t->status === 'in_use');
        if ($inUse->isNotEmpty()) {
            return response()->json([
                'message' => 'Cannot delete token(s) in use.',
                'in_use_ids' => $inUse->pluck('id')->values()->all(),
            ], 409);
        }

        $deleted = Token::query()->whereIn('id', $ids)->delete();

        return response()->json(['deleted' => $deleted]);
    }

    /**
     * Regenerate TTS audio. If token_ids provided, only those tokens; else all opted-in.
     * Marks tts_status as "generating" and dispatches the queue job.
     */
    public function regenerateTts(RegenerateTokenTtsRequest $request): JsonResponse
    {
        if (! $this->ttsService->isEnabled()) {
            return response()->json([
                'message' => 'Server TTS is not enabled.',
                'queued' => 0,
            ], 503);
        }

        if (TokenTtsSetting::instance()->getEffectiveVoiceId() === null) {
            return response()->json([
                'message' => 'Default TTS voice must be configured in Settings before generating token TTS.',
                'queued' => 0,
            ], 422);
        }

        $requestIds = $request->validated('token_ids');

        if (is_array($requestIds) && $requestIds !== []) {
            $tokenIds = array_values(array_map('intval', $requestIds));
            $workerIdle = QueueWorkerIdleCheck::appearsIdle();
            if ($workerIdle && ! config('tts.allow_sync_when_queue_unavailable', false)) {
                Token::query()->whereIn('id', $tokenIds)->update(['tts_pre_generate_enabled' => true, 'tts_status' => 'failed']);
                return response()->json([
                    'message' => 'Queue worker is not running. Start it with: php artisan queue:work',
                ], 503);
            }
            $maxSync = (int) config('tts.max_sync_tokens', 20);
            if ($workerIdle && config('tts.allow_sync_when_queue_unavailable', false) && count($tokenIds) > $maxSync) {
                Token::query()->whereIn('id', $tokenIds)->update(['tts_pre_generate_enabled' => true, 'tts_status' => 'failed']);
                return response()->json([
                    'message' => 'Queue worker is required for large batches. Start it with: php artisan queue:work, or select '.$maxSync.' or fewer tokens.',
                ], 503);
            }
            Token::query()
                ->whereIn('id', $tokenIds)
                ->update([
                    'tts_pre_generate_enabled' => true,
                    'tts_status' => 'generating',
                ]);
        } else {
            $tokenIds = Token::query()
                ->where('tts_pre_generate_enabled', true)
                ->pluck('id')
                ->all();

            if ($tokenIds === []) {
                return response()->json([
                    'queued' => 0,
                ]);
            }

            $workerIdle = QueueWorkerIdleCheck::appearsIdle();
            if ($workerIdle && ! config('tts.allow_sync_when_queue_unavailable', false)) {
                Token::query()
                    ->whereIn('id', $tokenIds)
                    ->update(['tts_status' => 'failed']);
                return response()->json([
                    'message' => 'Queue worker is not running. Start it with: php artisan queue:work',
                ], 503);
            }
            $maxSync = (int) config('tts.max_sync_tokens', 20);
            if ($workerIdle && config('tts.allow_sync_when_queue_unavailable', false) && count($tokenIds) > $maxSync) {
                Token::query()
                    ->whereIn('id', $tokenIds)
                    ->update(['tts_status' => 'failed']);
                return response()->json([
                    'message' => 'Queue worker is required for large batches. Start it with: php artisan queue:work, or select '.$maxSync.' or fewer tokens.',
                ], 503);
            }
            // Clear tokens stuck in "generating" (no worker, job died), but never touch tokens we're about to generate.
            Token::query()
                ->where('tts_status', 'generating')
                ->where('updated_at', '<', now()->subMinutes(5))
                ->whereNotIn('id', $tokenIds)
                ->update(['tts_status' => 'failed']);

            Token::query()
                ->whereIn('id', $tokenIds)
                ->update([
                    'tts_status' => 'generating',
                ]);
        }

        $workerIdle = QueueWorkerIdleCheck::appearsIdle();
        if ($workerIdle && config('tts.allow_sync_when_queue_unavailable', false)) {
            GenerateTokenTtsJob::dispatchSync($tokenIds);
        } else {
            GenerateTokenTtsJob::dispatch($tokenIds);
        }

        return response()->json([
            'queued' => count($tokenIds),
        ]);
    }

    private function tokenResource(Token $token): array
    {
        $ttsSettings = $token->tts_settings ?? [];

        return [
            'id' => $token->id,
            'physical_id' => $token->physical_id,
            'pronounce_as' => $token->pronounce_as ?? 'letters',
            'qr_code_hash' => $token->qr_code_hash,
            'status' => $token->status,
            'tts_status' => $token->tts_status,
            'tts_failure_reason' => is_array($ttsSettings) ? ($ttsSettings['failure_reason'] ?? null) : null,
            'has_tts_audio' => $token->tts_audio_path !== null,
            'tts_settings' => $ttsSettings,
        ];
    }
}
