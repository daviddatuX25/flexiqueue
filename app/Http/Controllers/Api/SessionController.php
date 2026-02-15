<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\StepsRemainingException;
use App\Exceptions\TokenInUseException;
use App\Exceptions\TokenUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BindSessionRequest;
use App\Http\Requests\CancelSessionRequest;
use App\Http\Requests\ForceCompleteSessionRequest;
use App\Http\Requests\OverrideSessionRequest;
use App\Http\Requests\TransferSessionRequest;
use App\Models\Session;
use App\Services\PinService;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per 08-API-SPEC-PHASE1 §3: Session endpoints (bind, etc.). Auth: any staff.
 */
class SessionController extends Controller
{
    public function __construct(
        private SessionService $sessionService,
        private PinService $pinService
    ) {}

    /**
     * Bind token to new session. Per spec §3.1.
     */
    public function bind(BindSessionRequest $request): JsonResponse
    {
        try {
            $result = $this->sessionService->bind(
                $request->validated('qr_hash'),
                (int) $request->validated('track_id'),
                $request->validated('client_category'),
                $request->user()->id
            );
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

            return response()->json(['message' => $e->getMessage()], $code === 422 ? 422 : 400);
        } catch (TokenInUseException $e) {
            $s = $e->activeSession;
            $s->load('currentStation');

            return response()->json([
                'message' => 'Token is already in use.',
                'active_session' => [
                    'id' => $s->id,
                    'alias' => $s->alias,
                    'status' => $s->status,
                    'current_station' => $s->currentStation ? $s->currentStation->name : null,
                    'started_at' => $s->started_at?->toIso8601String(),
                ],
            ], 409);
        } catch (TokenUnavailableException $e) {
            return response()->json([
                'message' => 'Token is marked as '.$e->tokenStatus.'.',
                'token_status' => $e->tokenStatus,
            ], 409);
        }

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

    /**
     * Per plan: Call session — sets 'called' (announce). Staff clicks Serve when client shows.
     */
    public function call(Session $session): JsonResponse
    {
        try {
            $result = $this->sessionService->call($session, $this->user()->id);
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            if ($code === 0) {
                $code = 409;
            }

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return response()->json($result);
    }

    /**
     * Per plan: Serve session — client showed, staff clicks Serve. From 'called' only.
     */
    public function serve(Session $session): JsonResponse
    {
        try {
            $result = $this->sessionService->serve($session, $this->user()->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'session' => $this->formatSession($result['session']),
        ]);
    }

    /**
     * Per 08-API-SPEC-PHASE1 §3.2: Transfer session to next station.
     */
    public function transfer(TransferSessionRequest $request, Session $session): JsonResponse
    {
        try {
            $result = $this->sessionService->transfer(
                $session,
                $request->validated('mode'),
                $request->validated('target_station_id'),
                $request->user()->id
            );
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode() ?: 409;

            return response()->json(['message' => $e->getMessage()], (int) $code);
        }

        if (isset($result['action_required'])) {
            return response()->json([
                'message' => $result['message'],
                'session' => $this->formatSession($result['session']),
                'action_required' => $result['action_required'],
            ]);
        }

        return response()->json([
            'session' => $this->formatSession($result['session']),
            'previous_station' => $result['previous_station'] ?? null,
        ]);
    }

    /**
     * Per 08-API-SPEC-PHASE1 §3.4: Complete session at final station.
     */
    public function complete(Session $session): JsonResponse
    {
        try {
            $result = $this->sessionService->complete($session, $this->user()->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (StepsRemainingException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'remaining_steps' => $e->remainingSteps,
            ], 409);
        }

        return response()->json([
            'session' => $this->formatSession($result['session']),
            'token' => $result['token'],
        ]);
    }

    /**
     * Per 08-API-SPEC-PHASE1 §3.5: Cancel session.
     */
    public function cancel(CancelSessionRequest $request, Session $session): JsonResponse
    {
        try {
            $result = $this->sessionService->cancel($session, $this->user()->id, $request->validated('remarks'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'session' => $this->formatSession($result['session']),
            'token' => $result['token'],
        ]);
    }

    /**
     * Per plan: Mark no-show. From 'called' or 'waiting'.
     * If attempts < 3: back to waiting. If 3: terminates, returns token.
     */
    public function noShow(Session $session): JsonResponse
    {
        try {
            $result = $this->sessionService->markNoShow($session, $this->user()->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $response = [
            'session' => $this->formatSession($result['session']),
        ];
        if (isset($result['token'])) {
            $response['token'] = $result['token'];
        }
        if (isset($result['back_to_waiting'])) {
            $response['back_to_waiting'] = true;
            $response['no_show_attempts'] = $result['no_show_attempts'] ?? 0;
        }

        return response()->json($response);
    }

    /**
     * Per 08-API-SPEC-PHASE1 §3.8: Force-complete (supervisor PIN required).
     */
    public function forceComplete(ForceCompleteSessionRequest $request, Session $session): JsonResponse
    {
        $validated = $request->validated();
        $authType = $validated['auth_type'] ?? 'preset_pin';
        $verified = match ($authType) {
            'temp_pin' => $this->pinService->validateTemporaryPin($validated['temp_code'] ?? ''),
            'temp_qr' => $this->pinService->validateTemporaryQr($validated['qr_scan_token'] ?? ''),
            default => $this->pinService->validate((int) $validated['supervisor_user_id'], $validated['supervisor_pin'] ?? ''),
        };

        if (! $verified) {
            $message = in_array($authType, ['temp_pin', 'temp_qr'], true)
                ? 'Authorization expired. Request a new one.'
                : 'Invalid supervisor PIN.';
            return response()->json(['message' => $message], 401);
        }

        try {
            $result = $this->sessionService->forceComplete(
                $session,
                $request->validated('reason'),
                $verified['user_id'],
                $request->user()->id
            );
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode() ?: 409;
            if ($code === 401) {
                return response()->json(['message' => 'Invalid supervisor PIN.'], 401);
            }

            return response()->json(['message' => $e->getMessage()], (int) $code);
        }

        return response()->json([
            'session' => $this->formatSession($result['session']),
            'token' => $result['token'],
        ]);
    }

    /**
     * Per 08-API-SPEC-PHASE1 §3.3: Override (supervisor route deviation).
     */
    public function override(OverrideSessionRequest $request, Session $session): JsonResponse
    {
        $validated = $request->validated();
        $authType = $validated['auth_type'] ?? 'preset_pin';
        $verified = match ($authType) {
            'temp_pin' => $this->pinService->validateTemporaryPin($validated['temp_code'] ?? ''),
            'temp_qr' => $this->pinService->validateTemporaryQr($validated['qr_scan_token'] ?? ''),
            default => $this->pinService->validate((int) $validated['supervisor_user_id'], $validated['supervisor_pin'] ?? ''),
        };

        if (! $verified) {
            $message = in_array($authType, ['temp_pin', 'temp_qr'], true)
                ? 'Authorization expired. Request a new one.'
                : 'Invalid supervisor PIN.';
            return response()->json(['message' => $message], 401);
        }

        try {
            $result = $this->sessionService->override(
                $session,
                (int) $request->validated('target_station_id'),
                $request->validated('reason'),
                $verified['user_id'],
                $request->user()->id
            );
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode() ?: 409;
            if ($code === 401) {
                return response()->json(['message' => 'Invalid supervisor PIN.'], 401);
            }

            return response()->json(['message' => $e->getMessage()], (int) $code);
        }

        return response()->json([
            'session' => $this->formatSession($result['session']),
            'override' => $result['override'],
        ]);
    }

    /**
     * Format session for JSON response.
     *
     * @return array<string, mixed>
     */
    private function formatSession(Session $session): array
    {
        $session->loadMissing(['currentStation', 'serviceTrack']);
        $data = [
            'id' => $session->id,
            'alias' => $session->alias,
            'status' => $session->status,
            'current_step_order' => $session->current_step_order,
            'started_at' => $session->started_at?->toIso8601String(),
            'completed_at' => $session->completed_at?->toIso8601String(),
            'no_show_attempts' => $session->no_show_attempts ?? 0,
        ];
        if ($session->relationLoaded('currentStation') && $session->currentStation) {
            $data['current_station'] = ['id' => $session->currentStation->id, 'name' => $session->currentStation->name];
        }
        if ($session->relationLoaded('serviceTrack') && $session->serviceTrack) {
            $data['track'] = ['id' => $session->serviceTrack->id, 'name' => $session->serviceTrack->name];
        }

        return $data;
    }

    private function user(): \App\Models\User
    {
        return request()->user();
    }

    /**
     * Look up token by physical_id for manual triage entry. Returns qr_hash for bind.
     */
    public function tokenLookup(Request $request): JsonResponse
    {
        $physicalId = $request->query('physical_id');
        if (! is_string($physicalId) || $physicalId === '') {
            return response()->json(['message' => 'physical_id is required.'], 422);
        }

        $token = \App\Models\Token::where('physical_id', $physicalId)->first();
        if (! $token) {
            return response()->json(['message' => 'Token not found.'], 404);
        }

        return response()->json([
            'physical_id' => $token->physical_id,
            'qr_hash' => $token->qr_code_hash,
            'status' => $token->status,
        ]);
    }
}
