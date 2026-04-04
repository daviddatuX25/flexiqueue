<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BatchCreateTokenRequest;
use App\Http\Requests\BatchDeleteTokenRequest;
use App\Http\Requests\RegenerateTokenTtsRequest;
use App\Http\Requests\UpdateTokenRequest;
use App\Jobs\GenerateTokenTtsJob;
use App\Models\Program;
use App\Models\Token;
use App\Repositories\TokenTtsSettingRepository;
use App\Services\TokenService;
use App\Services\Tts\TtsLanguageStatusPresenter;
use App\Services\TtsService;
use App\Support\QueueWorkerIdleCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per 08-API-SPEC-PHASE1 §5.5: Token list, batch create, update status. Auth: role:admin.
 * Per site-scoping-migration-spec §2: index/store/update/destroy/batch scoped by site; super_admin optional ?site_id= or all.
 */
class TokenController extends Controller
{
    public function __construct(
        private TokenService $tokenService,
        private TtsService $ttsService,
        private TokenTtsSettingRepository $tokenTtsSettingRepository,
        private TtsLanguageStatusPresenter $ttsLanguageStatusPresenter
    ) {}

    /**
     * List tokens. Filterable: ?status=available|in_use, ?search= (physical_id substring).
     * Per site-scoping-migration-spec §2: site admin sees only their site (403 if site_id null); super_admin optional ?site_id= or all.
     * Soft-deleted tokens excluded by default.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Token::query()->orderBy('physical_id');

        if ($user->isSuperAdmin()) {
            $siteId = $request->filled('site_id') ? (int) $request->input('site_id') : null;
            if ($siteId !== null) {
                $query->forSite($siteId);
            }
        } else {
            $siteId = $user->site_id;
            if ($siteId === null) {
                return response()->json(['message' => 'You must be assigned to a site to list tokens.'], 403);
            }
            $query->forSite($siteId);
        }

        $query->with(['programs:id,name']);

        if ($request->has('status') && $request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->has('search') && $request->filled('search')) {
            $query->where('physical_id', 'like', '%'.$request->input('search').'%');
        }
        if ($request->filled('prefix')) {
            $query->where('physical_id', 'like', $request->input('prefix').'%');
        }
        if ($request->has('is_global') && $request->input('is_global') !== '') {
            $query->where('is_global', (bool) $request->input('is_global'));
        }
        if ($request->filled('tts_status')) {
            $val = $request->input('tts_status');
            if ($val === 'not_generated' || $val === 'null') {
                $query->whereNull('tts_status');
            } else {
                $query->where('tts_status', $val);
            }
        }
        if ($request->filled('assignment')) {
            $assignment = $request->input('assignment');
            if ($assignment === 'unassigned') {
                $query->whereDoesntHave('programs');
            } elseif ($assignment === 'global') {
                $query->where('is_global', true);
            } elseif (preg_match('/^program_id:(\d+)$/', (string) $assignment, $m)) {
                $programId = (int) $m[1];
                $program = Program::query()->where('id', $programId)->first();
                if ($program && ($siteId === null || (int) $program->site_id === (int) $siteId)) {
                    $query->whereHas('programs', fn ($q) => $q->where('programs.id', $programId));
                }
            }
        }

        $perPage = (int) $request->input('per_page', 25);
        $perPage = max(10, min(100, $perPage));
        $paginator = $query->paginate($perPage);
        $tokens = $paginator->getCollection()->map(fn (Token $t) => $this->tokenResource($t));

        return response()->json([
            'tokens' => $tokens->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Create token batch. Per spec: 201 with created count and tokens array.
     * Per site-scoping-migration-spec §2: site_id from auth; 403 if site admin has no site_id.
     */
    public function batch(BatchCreateTokenRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isSuperAdmin() && $user->site_id === null) {
            return response()->json(['message' => 'You must be assigned to a site to create tokens.'], 403);
        }

        $validated = $request->validated();
        $pronounceAs = $validated['pronounce_as'] ?? 'letters';
        if ($this->ttsPayloadHasNonEmptyTokenPhrase($validated['tts'] ?? [])) {
            $pronounceAs = 'custom';
        }
        $allowSyncFallback = (bool) config('tts.allow_sync_when_queue_unavailable', false);
        $maxSync = (int) config('tts.max_sync_tokens', 20);
        $workerIdle = QueueWorkerIdleCheck::appearsIdle();
        $shouldEnforceSyncCap = $workerIdle || (app()->environment('testing') && config('queue.default') === 'database');
        $generateOfflineTts = $validated['generate_tts'] ?? true;
        if (
            $generateOfflineTts
            && $allowSyncFallback
            && $shouldEnforceSyncCap
            && (int) $validated['count'] > $maxSync
            && $this->ttsService->isEnabled()
            && $this->tokenTtsSettingRepository->getInstance()->getEffectiveVoiceId() !== null
        ) {
            return response()->json([
                'message' => 'Queue worker is required for large batches. Start it with: php artisan queue:work, or reduce batch size to '.$maxSync.' or fewer.',
            ], 503);
        }

        $siteId = $user->isSuperAdmin() ? null : $user->site_id;
        $isGlobal = $validated['is_global'] ?? true;
        $result = $this->tokenService->batchCreate(
            $validated['prefix'],
            $validated['count'],
            $validated['start_number'],
            $pronounceAs,
            $siteId,
            $isGlobal
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

                    if (array_key_exists('token_phrase', $langInput)) {
                        $value = $langInput['token_phrase'];
                        $config['token_phrase'] = is_string($value) && trim($value) !== '' ? trim($value) : null;
                    }

                    $token->setTtsConfigFor($lang, $config);
                }

                $token->save();
            }
        }

        if ($tokenIds !== []) {
            Token::query()
                ->whereIn('id', $tokenIds)
                ->update([
                    'tts_pre_generate_enabled' => $generateOfflineTts,
                ]);

            if ($generateOfflineTts && $this->ttsService->isEnabled() && $this->tokenTtsSettingRepository->getInstance()->getEffectiveVoiceId() !== null) {
                $workerIdle = QueueWorkerIdleCheck::appearsIdle();
                if ($workerIdle && ! $allowSyncFallback) {
                    Token::query()
                        ->whereIn('id', $tokenIds)
                        ->update(['tts_status' => 'failed']);

                    return response()->json([
                        'message' => 'Queue worker is not running. Start it with: php artisan queue:work',
                    ], 503);
                }
                $maxSync = (int) config('tts.max_sync_tokens', 20);
                $allowSyncFallback = (bool) config('tts.allow_sync_when_queue_unavailable', false);
                $shouldEnforceSyncCap = $workerIdle || (app()->environment('testing') && config('queue.default') === 'database');
                if ($allowSyncFallback && $shouldEnforceSyncCap && count($tokenIds) > $maxSync) {
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

                if ($workerIdle && $allowSyncFallback) {
                    GenerateTokenTtsJob::dispatchSync($tokenIds);
                } else {
                    GenerateTokenTtsJob::dispatch($tokenIds);
                }
            }
        }

        return response()->json($result, 201);
    }

    /**
     * Update token. Admin can set status (available/deactivated) and/or pronounce_as (letters/word/custom).
     * Cannot deactivate a token that is in_use.
     * Per site-scoping-migration-spec §2: token must belong to user's site or 404.
     */
    public function update(UpdateTokenRequest $request, Token $token): JsonResponse
    {
        $this->ensureTokenInUserSite($request, $token);

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

        $shouldSetPronounce = array_key_exists('pronounce_as', $validated)
            || $this->ttsPayloadHasNonEmptyTokenPhrase($validated['tts'] ?? []);

        if ($shouldSetPronounce) {
            $finalPronounce = array_key_exists('pronounce_as', $validated)
                ? $validated['pronounce_as']
                : ($token->pronounce_as ?? 'letters');
            if ($this->ttsPayloadHasNonEmptyTokenPhrase($validated['tts'] ?? [])) {
                $finalPronounce = 'custom';
            }
            $updates['pronounce_as'] = $finalPronounce;
        }

        if (array_key_exists('is_global', $validated)) {
            $updates['is_global'] = (bool) $validated['is_global'];
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

                if (array_key_exists('token_phrase', $langInput)) {
                    $value = $langInput['token_phrase'];
                    $config['token_phrase'] = is_string($value) && trim($value) !== '' ? trim($value) : null;
                }

                $token->setTtsConfigFor($lang, $config);
            }

            $token->save();
            $token->refresh();
        }

        $this->normalizeNonCustomPerLangTts($token);
        $token->refresh();

        return response()->json(['token' => $this->tokenResource($token)]);
    }

    /**
     * Soft delete a single token. Fails with 409 if token is in_use.
     * Per site-scoping-migration-spec §2: token must belong to user's site or 404.
     */
    public function destroy(Token $token): JsonResponse
    {
        $this->ensureTokenInUserSite(request(), $token);

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
     * Per site-scoping-migration-spec §2: all tokens must belong to user's site or 403.
     */
    public function batchDelete(BatchDeleteTokenRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isSuperAdmin() && $user->site_id === null) {
            return response()->json(['message' => 'You must be assigned to a site to delete tokens.'], 403);
        }

        $ids = $request->validated('ids');
        $query = Token::query()->whereIn('id', $ids);
        if (! $user->isSuperAdmin()) {
            $query->forSite($user->site_id);
        }
        $tokens = $query->get();

        if ($tokens->count() !== count($ids)) {
            return response()->json(['message' => 'One or more tokens are not in your site or do not exist.'], 403);
        }

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
     * Regenerate TTS audio. If token_ids provided, only those tokens; else all opted-in in user's site.
     * Marks tts_status as "generating" and dispatches the queue job.
     * Per site-scoping-migration-spec §2: token_ids must be in user's site; "all" scoped by site.
     */
    public function regenerateTts(RegenerateTokenTtsRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isSuperAdmin() && $user->site_id === null) {
            return response()->json(['message' => 'You must be assigned to a site to regenerate token TTS.'], 403);
        }

        if (! $this->ttsService->isEnabled()) {
            return response()->json([
                'message' => 'Server TTS is not enabled.',
                'queued' => 0,
            ], 503);
        }

        if ($this->tokenTtsSettingRepository->getInstance()->getEffectiveVoiceId() === null) {
            return response()->json([
                'message' => 'Default TTS voice must be configured in Settings before generating token TTS.',
                'queued' => 0,
            ], 422);
        }

        $requestIds = $request->validated('token_ids');
        $siteId = $user->isSuperAdmin() ? null : $user->site_id;

        if (is_array($requestIds) && $requestIds !== []) {
            $tokenIds = array_values(array_map('intval', $requestIds));
            $query = Token::query()->whereIn('id', $tokenIds);
            if ($siteId !== null) {
                $query->forSite($siteId);
            }
            $found = $query->pluck('id')->all();
            if (count($found) !== count($tokenIds)) {
                return response()->json(['message' => 'One or more tokens are not in your site or do not exist.'], 403);
            }
            $tokenIds = $found;
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
            $query = Token::query()->where('tts_pre_generate_enabled', true);
            if ($siteId !== null) {
                $query->forSite($siteId);
            }
            $tokenIds = $query->pluck('id')->all();

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

    /**
     * @param  array<string, mixed>  $tts
     */
    private function ttsPayloadHasNonEmptyTokenPhrase(array $tts): bool
    {
        foreach (['en', 'fil', 'ilo'] as $lang) {
            if (! isset($tts[$lang]) || ! is_array($tts[$lang])) {
                continue;
            }
            $v = $tts[$lang]['token_phrase'] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Letters/word modes use site defaults only for per-lang voice, rate, pre-phrase, and token phrase.
     */
    private function normalizeNonCustomPerLangTts(Token $token): void
    {
        if (($token->pronounce_as ?? 'letters') === 'custom') {
            return;
        }
        $dirty = false;
        foreach (['en', 'fil', 'ilo'] as $lang) {
            $config = $token->getTtsConfigFor($lang);
            foreach (['token_phrase', 'pre_phrase', 'voice_id'] as $key) {
                if (! array_key_exists($key, $config)) {
                    continue;
                }
                if ($config[$key] !== null) {
                    $config[$key] = null;
                    $dirty = true;
                }
            }
            if (array_key_exists('rate', $config)) {
                unset($config['rate']);
                $dirty = true;
            }
            $token->setTtsConfigFor($lang, $config);
        }
        if ($dirty) {
            $token->save();
        }
    }

    /** Per site-scoping-migration-spec §2: ensure token belongs to user's site. 404 if not. */
    private function ensureTokenInUserSite(Request $request, Token $token): void
    {
        $user = $request->user();
        if ($user->isSuperAdmin()) {
            return;
        }
        if ($user->site_id === null) {
            abort(403, 'You must be assigned to a site to access this resource.');
        }
        if ($token->site_id !== $user->site_id) {
            abort(404);
        }
    }

    private function tokenResource(Token $token): array
    {
        $ttsSettings = $token->tts_settings ?? [];
        if (! is_array($ttsSettings)) {
            $ttsSettings = [];
        }
        $ttsSettings['languages'] = $this->ttsLanguageStatusPresenter->present(
            is_array($ttsSettings['languages'] ?? null) ? $ttsSettings['languages'] : []
        );

        $assignedPrograms = $token->relationLoaded('programs')
            ? $token->programs->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all()
            : [];

        return [
            'id' => $token->id,
            'physical_id' => $token->physical_id,
            'pronounce_as' => $token->pronounce_as ?? 'letters',
            'qr_code_hash' => $token->qr_code_hash,
            'status' => $token->status,
            'current_session_id' => $token->current_session_id,
            'is_global' => (bool) $token->is_global,
            'assigned_programs' => $assignedPrograms,
            'tts_status' => $token->tts_status,
            'tts_failure_reason' => is_array($ttsSettings) ? ($ttsSettings['failure_reason'] ?? null) : null,
            'has_tts_audio' => $token->tts_audio_path !== null,
            'tts_settings' => $ttsSettings,
        ];
    }
}
