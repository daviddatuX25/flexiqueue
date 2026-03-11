<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TokenInUseException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BindSessionRequest;
use App\Models\Program;
use App\Models\Token;
use App\Services\SessionService;
use App\Services\TriageScanLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public triage API: token lookup and bind when program allows public self-serve.
 * No auth. Rate limited. Returns 403 when allow_public_triage is false.
 */
class PublicTriageController extends Controller
{
    private const TOKEN_LOOKUP_THROTTLE = 'public_triage_lookup:';

    private const BIND_THROTTLE = 'public_triage_bind:';

    private const TOKEN_LOOKUP_MAX = 30;

    private const BIND_MAX = 20;

    public function __construct(
        private SessionService $sessionService,
        private TriageScanLogService $triageScanLogService,
    ) {}

    /**
     * GET /api/public/token-lookup?qr_hash=... or ?physical_id=...
     * Returns { physical_id, qr_hash, status }. 403 when public triage disabled.
     */
    public function tokenLookup(Request $request): JsonResponse
    {
        $key = self::TOKEN_LOOKUP_THROTTLE.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::TOKEN_LOOKUP_MAX)) {
            return response()->json(['message' => 'Too many requests. Try again later.'], 429);
        }

        $program = Program::where('is_active', true)->first();
        if (! $program || ! $program->settings()->getAllowPublicTriage()) {
            return response()->json(['message' => 'Public self-serve triage is not available.'], 403);
        }

        $physicalId = $request->query('physical_id');
        $qrHash = $request->query('qr_hash');

        $token = null;
        if (is_string($qrHash) && $qrHash !== '') {
            $token = Token::where('qr_code_hash', $qrHash)->first();
        } elseif (is_string($physicalId) && $physicalId !== '') {
            $token = Token::where('physical_id', $physicalId)->first();
        }

        if (! $token) {
            if (! (is_string($physicalId) && $physicalId !== '') && ! (is_string($qrHash) && $qrHash !== '')) {
                return response()->json(['message' => 'physical_id or qr_hash is required.'], 422);
            }
            $this->triageScanLogService->log($request, null, 'not_found', null, null);
            RateLimiter::hit($key);

            return response()->json(['message' => 'Token not found.'], 404);
        }

        $this->triageScanLogService->log($request, $token->id, $token->status, $token->physical_id, $token->qr_code_hash);
        RateLimiter::hit($key);

        return response()->json([
            'physical_id' => $token->physical_id,
            'qr_hash' => $token->qr_code_hash,
            'status' => $token->status,
        ]);
    }

    /**
     * POST /api/public/sessions/bind — qr_hash, track_id, optional client_category (default Regular).
     * 403 when public triage disabled. 201 on success.
     */
    public function bind(BindSessionRequest $request): JsonResponse
    {
        $key = self::BIND_THROTTLE.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::BIND_MAX)) {
            return response()->json(['message' => 'Too many requests. Try again later.'], 429);
        }

        $program = Program::where('is_active', true)->first();
        if (! $program || ! $program->settings()->getAllowPublicTriage()) {
            return response()->json(['message' => 'Public self-serve triage is not available.'], 403);
        }

        $qrHash = $request->validated('qr_hash');
        $trackId = (int) $request->validated('track_id');
        $clientCategory = $request->validated('client_category') ?? 'Regular';

        try {
            $result = $this->sessionService->bind($qrHash, $trackId, $clientCategory, null);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode() === 422 ? 422 : 400;
            if (str_contains($e->getMessage(), 'Token not found')) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['qr_hash' => ['Token not found.']],
                ], 422);
            }
            if (str_contains($e->getMessage(), 'Track does not belong')) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['track_id' => ['Track does not belong to the active program.']],
                ], 422);
            }
            if (str_contains($e->getMessage(), 'no steps')) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['track_id' => ['Track has no steps defined.']],
                ], 422);
            }
            if (str_contains($e->getMessage(), 'deactivated')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json(['message' => $e->getMessage()], $code);
        } catch (TokenInUseException $e) {
            $s = $e->activeSession;
            $s->load('currentStation');

            return response()->json([
                'message' => 'Token is already in use.',
                'active_session' => [
                    'alias' => $s->alias,
                    'status' => $s->status,
                    'current_station' => $s->currentStation?->name,
                    'started_at' => $s->started_at?->toIso8601String(),
                ],
            ], 409);
        }

        RateLimiter::hit($key);

        $session = $result['session'];
        $session->load('currentStation', 'serviceTrack');

        return response()->json([
            'session' => [
                'id' => $session->id,
                'alias' => $session->alias,
                'track' => [
                    'id' => $session->serviceTrack->id,
                    'name' => $session->serviceTrack->name,
                ],
                'client_category' => $session->client_category,
                'status' => $session->status,
                'current_station' => [
                    'id' => $session->currentStation->id,
                    'name' => $session->currentStation->name,
                ],
                'current_step_order' => $session->current_step_order,
                'started_at' => $session->started_at?->toIso8601String(),
            ],
            'token' => $result['token'],
        ], 201);
    }
}
