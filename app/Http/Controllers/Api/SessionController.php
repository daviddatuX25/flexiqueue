<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ClientAlreadyQueuedException;
use App\Exceptions\StepsRemainingException;
use App\Exceptions\TokenInUseException;
use App\Exceptions\TokenUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BindSessionRequest;
use App\Http\Requests\CallSessionRequest;
use App\Http\Requests\CancelSessionRequest;
use App\Http\Requests\ForceCompleteSessionRequest;
use App\Http\Requests\HoldSessionRequest;
use App\Http\Requests\OverrideSessionRequest;
use App\Http\Requests\EnqueueBackSessionRequest;
use App\Http\Requests\MarkNoShowSessionRequest;
use App\Http\Requests\ResumeFromHoldSessionRequest;
use App\Http\Requests\ServeSessionRequest;
use App\Http\Requests\TransferSessionRequest;
use App\Http\Resources\SessionResource;
use App\Exceptions\IdentityBindingException;
use App\Http\Controllers\StationPageController;
use App\Models\Program;
use App\Models\Session;
use App\Models\User;
use App\Services\PinService;
use App\Services\SessionService;
use App\Services\SupervisorAuthService;
use App\Services\TokenService;
use App\Services\TriageScanLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use App\Support\SupervisorAuthResult;

/**
 * Per 08-API-SPEC-PHASE1 §3: Session endpoints (bind, etc.). Auth: any staff.
 * Per PIN-QR-AUTHORIZATION-SYSTEM §3.2: Wrong PIN 5 times → rate limit 15 min.
 */
class SessionController extends Controller
{
    private const PIN_FAIL_THROTTLE_PREFIX = 'pin_auth_fail:';

    private const PIN_FAIL_MAX_ATTEMPTS = 5;

    private const PIN_FAIL_DECAY_MINUTES = 15;

    public function __construct(
        private SessionService $sessionService,
        private PinService $pinService,
        private SupervisorAuthService $supervisorAuthService,
        private TokenService $tokenService,
        private TriageScanLogService $triageScanLogService,
    ) {}

    /**
     * Bind token to new session. Per spec §3.1.
     * Per central-edge A.2.2: staff must have assigned station; program resolved from station.
     */
    public function bind(BindSessionRequest $request): JsonResponse
    {
        $user = $request->user();
        $programId = $user->assignedStation?->program_id;

        // Per central-edge follow-up: admin/supervisor with no assigned station uses session-selected program context.
        if ($programId === null) {
            if (! $user->isAdmin() && ! $user->isSupervisorForAnyProgram()) {
                return response()->json(['message' => 'No station assigned.'], 422);
            }

            $programId = $request->validated('program_id');
            if ($programId === null) {
                $programId = $request->session()->get(StationPageController::SESSION_KEY_PROGRAM_ID);
            }

            $program = $programId ? Program::query()->where('id', (int) $programId)->where('is_active', true)->first() : null;
            if (! $program) {
                return response()->json(['message' => 'Program not selected or inactive.'], 422);
            }
            if (! $user->isAdmin() && ! $user->isSupervisorForProgram($program->id)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $programId = $program->id;
        }

        try {
            $result = $this->sessionService->bind(
                $request->validated('qr_hash'),
                (int) $request->validated('track_id'),
                $request->validated('client_category'),
                $user->id,
                $request->validated('client_binding'),
                null,
                null,
                $programId
            );
        } catch (IdentityBindingException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => [
                    'client_binding' => [$e->getMessage()],
                ],
            ], 422);
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
        } catch (ClientAlreadyQueuedException $e) {
            $s = $e->activeSession;
            $s->load('currentStation');

            return response()->json([
                'message' => 'Client already has an active visit.',
                'error_code' => 'client_already_queued',
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
     * When program requires permission and call would skip priority (FIFO calling regular before PWD), auth required.
     */
    public function call(CallSessionRequest $request, Session $session): JsonResponse
    {
        $user = $request->user();
        // Per docs/REFACTORING-ISSUE-LIST.md Issue 1: supervisor auth via SupervisorAuthService::verifyForAction.
        if ($this->sessionService->callRequiresOverrideAuth($session) && ! $user->isAdmin() && ! $user->isSupervisorForAnyProgram()) {
            $result = $this->supervisorAuthService->verifyForAction(
                $request->validated(),
                $user,
                $session,
                'call'
            );
            if (! $result->isOk()) {
                return $this->respondSupervisorAuthFailure($result, 'Calling this client would skip priority clients. Supervisor authorization required.');
            }
        }

        try {
            $result = $this->sessionService->call($session, $request->user()->id);
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
     * Map SupervisorAuthResult failure to HTTP JSON. Optional message for missing/invalid auth type (e.g. call-specific).
     */
    private function respondSupervisorAuthFailure(SupervisorAuthResult $result, ?string $requiredAuthMessage = null): JsonResponse
    {
        $defaultRequiredMessage = 'Supervisor authorization required.';
        $requiredMessage = $requiredAuthMessage ?? $defaultRequiredMessage;

        return match ($result->code()) {
            'missing_auth_type', 'invalid_auth_type' => response()->json(['message' => $requiredMessage], 401),
            'rate_limited' => response()->json(['message' => 'Too many attempts. Try again in 15 minutes.'], 429),
            'expired_temp' => response()->json(['message' => 'Authorization expired. Request a new one.'], 401),
            'unauthorized_program' => response()->json(['message' => 'You are not a supervisor for this program. Preset authorization cannot be used here.'], 403),
            default => response()->json(['message' => 'Invalid supervisor PIN.'], 401),
        };
    }

    /**
     * Per plan: Serve session — client showed, staff clicks Serve. From 'called' or 'waiting'.
     * When session is 'waiting', station_id is required in request body.
     */
    public function serve(ServeSessionRequest $request, Session $session): JsonResponse
    {
        if ($session->status === 'waiting' && $request->validated('station_id') === null) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['station_id' => ['Station context is required when serving from waiting.']],
            ], 422);
        }

        try {
            $result = $this->sessionService->serve(
                $session,
                $this->user()->id,
                $request->validated('station_id')
            );
        } catch (\InvalidArgumentException $e) {
            $code = (int) $e->getCode();
            $status = $code === 422 ? 422 : 409;

            return response()->json(['message' => $e->getMessage()], $status);
        }

        return response()->json([
            'session' => SessionResource::make($result['session'])->resolve(),
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
                'session' => SessionResource::make($result['session'])->resolve(),
                'action_required' => $result['action_required'],
            ]);
        }

        return response()->json([
            'session' => SessionResource::make($result['session'])->resolve(),
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
            'session' => SessionResource::make($result['session'])->resolve(),
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
            'session' => SessionResource::make($result['session'])->resolve(),
            'token' => $result['token'],
        ]);
    }

    /**
     * Per station-holding-area plan: move session to station holding area.
     */
    public function hold(HoldSessionRequest $request, Session $session): JsonResponse
    {
        Gate::authorize('update', $session);

        $station = $session->currentStation;
        if (! $station || $session->current_station_id !== $station->id) {
            return response()->json(['message' => 'Session is not at a station.', 'error_code' => 'invalid_state'], 422);
        }

        try {
            $this->sessionService->moveToHolding(
                $session,
                $station,
                $request->user()->id,
                $request->validated('remarks')
            );
        } catch (\InvalidArgumentException $e) {
            $msg = $e->getMessage();
            $code = (int) $e->getCode();
            $status = $code === 422 ? 422 : 409;
            $body = ['message' => $msg];
            if (str_contains($msg, 'Holding area is full') || str_contains($msg, 'full')) {
                $body['error_code'] = 'holding_full';
                $status = 422;
            } else {
                $body['error_code'] = 'invalid_state';
            }

            return response()->json($body, $status);
        }

        return response()->json([
            'message' => 'Session moved to holding',
            'session_id' => $session->id,
        ]);
    }

    /**
     * Per station-holding-area plan: resume session from station holding area.
     */
    public function resumeFromHold(ResumeFromHoldSessionRequest $request, Session $session): JsonResponse
    {
        Gate::authorize('update', $session);

        $station = $session->holdingStation;
        if (! $station || ! $session->isOnHold() || $session->holding_station_id !== $station->id) {
            return response()->json(['message' => 'Session is not on hold at this station.', 'error_code' => 'invalid_state'], 422);
        }

        try {
            $this->sessionService->resumeFromHolding(
                $session,
                $station,
                $request->user()->id,
                $request->validated('remarks')
            );
        } catch (\InvalidArgumentException $e) {
            $msg = $e->getMessage();
            $body = ['message' => $msg];
            if (str_contains($msg, 'at capacity') || str_contains($msg, 'Station at capacity')) {
                $body['error_code'] = 'at_capacity';

                return response()->json($body, 422);
            }
            $body['error_code'] = 'invalid_state';

            return response()->json($body, 409);
        }

        return response()->json([
            'message' => 'Session resumed',
            'session_id' => $session->id,
        ]);
    }

    /**
     * Per flexiqueue-a3wh: Enqueue session back to same station at end of queue.
     */
    public function enqueueBack(EnqueueBackSessionRequest $request, Session $session): JsonResponse
    {
        Gate::authorize('update', $session);

        try {
            $result = $this->sessionService->enqueueBack($session, $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'session' => SessionResource::make($result['session'])->resolve(),
            'back_to_waiting' => $result['back_to_waiting'],
        ]);
    }

    /**
     * Per flexiqueue-a3wh: Mark no-show. From 'called', 'waiting', or 'serving'.
     * Body: enqueue_back?, extend?, last_call? (booleans). When attempts >= max, exactly one of extend or last_call required.
     */
    public function noShow(MarkNoShowSessionRequest $request, Session $session): JsonResponse
    {
        Gate::authorize('update', $session);

        $enqueueBack = (bool) $request->input('enqueue_back', false);
        $extend = (bool) $request->input('extend', false);
        $lastCall = (bool) $request->input('last_call', false);

        if ($extend && $lastCall) {
            return response()->json(['message' => 'Cannot send both extend and last_call.'], 422);
        }

        $program = $session->program;
        $max = $program?->settings()->getMaxNoShowAttempts() ?? 3;
        $attempts = (int) $session->no_show_attempts;
        if ($attempts >= $max && ! $extend && ! $lastCall) {
            return response()->json(['message' => 'At max no-show attempts. Use extend or last_call.'], 422);
        }

        try {
            $result = $this->sessionService->markNoShow($session, $request->user()->id, $enqueueBack, $extend, $lastCall);
        } catch (\InvalidArgumentException $e) {
            $status = str_contains($e->getMessage(), 'max no-show') ? 422 : 409;

            return response()->json(['message' => $e->getMessage()], $status);
        }

        $response = [
            'session' => SessionResource::make($result['session'])->resolve(),
        ];
        if (isset($result['token'])) {
            $response['token'] = $result['token'];
        }
        if (isset($result['back_to_waiting'])) {
            $response['back_to_waiting'] = true;
            $response['no_show_attempts'] = $result['no_show_attempts'] ?? 0;
        }
        if (! empty($result['extended'])) {
            $response['extended'] = true;
        }

        return response()->json($response);
    }

    /**
     * Per 08-API-SPEC-PHASE1 §3.8: Force-complete (supervisor PIN required).
     */
    public function forceComplete(ForceCompleteSessionRequest $request, Session $session): JsonResponse
    {
        $user = $request->user();
        $staffUserId = $user->id;

        if ($user->isAdmin() || $user->isSupervisorForAnyProgram()) {
            try {
                $result = $this->sessionService->forceComplete(
                    $session,
                    $request->validated('reason') ?? '',
                    $staffUserId,
                    $staffUserId
                );
            } catch (\InvalidArgumentException $e) {
                $code = $e->getCode() ?: 409;
                return response()->json(['message' => $e->getMessage()], (int) $code);
            }

            return response()->json([
                'session' => SessionResource::make($result['session'])->resolve(),
                'token' => $result['token'],
            ]);
        }

        // Per flexiqueue-i87: When program has require_permission_before_override OFF, accept reason only (no PIN/QR).
        $session->loadMissing('program');
        $program = $session->program;
        if ($program && ! $program->settings()->getRequirePermissionBeforeOverride()) {
            try {
                $result = $this->sessionService->forceComplete(
                    $session,
                    $request->validated('reason') ?? '',
                    $staffUserId,
                    $staffUserId
                );
            } catch (\InvalidArgumentException $e) {
                $code = $e->getCode() ?: 409;

                return response()->json(['message' => $e->getMessage()], (int) $code);
            }

            return response()->json([
                'session' => SessionResource::make($result['session'])->resolve(),
                'token' => $result['token'],
            ]);
        }

        $result = $this->supervisorAuthService->verifyForAction(
            $request->validated(),
            $user,
            $session,
            'force_complete'
        );
        if (! $result->isOk()) {
            return $this->respondSupervisorAuthFailure($result);
        }

        try {
            $result = $this->sessionService->forceComplete(
                $session,
                $request->validated('reason') ?? '',
                $result->authorizerUserId,
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
            'session' => SessionResource::make($result['session'])->resolve(),
            'token' => $result['token'],
        ]);
    }

    /**
     * Per 08-API-SPEC-PHASE1 §3.3: Override (supervisor route deviation).
     */
    public function override(OverrideSessionRequest $request, Session $session): JsonResponse
    {
        $user = $request->user();
        $staffUserId = $user->id;

        if ($user->isAdmin() || $user->isSupervisorForAnyProgram()) {
            try {
                $result = $this->sessionService->overrideByTrack(
                    $session,
                    (int) $request->validated('target_track_id'),
                    $request->validated('reason') ?? '',
                    $staffUserId,
                    $staffUserId,
                    $this->sanitizeCustomSteps($request->validated('custom_steps'))
                );
            } catch (\InvalidArgumentException $e) {
                $code = $e->getCode() ?: 409;
                return response()->json(['message' => $e->getMessage()], (int) $code);
            }

            return response()->json([
                'session' => SessionResource::make($result['session'])->resolve(),
                'override' => $result['override'] ?? null,
            ]);
        }

        // Per flexiqueue-i87: When program has require_permission_before_override OFF, accept reason only (no PIN/QR).
        $session->loadMissing('program');
        $program = $session->program;
        $customSteps = $this->sanitizeCustomSteps($request->validated('custom_steps'));
        if ($program && ! $program->settings()->getRequirePermissionBeforeOverride()) {
            try {
                $result = $this->sessionService->overrideByTrack(
                    $session,
                    (int) $request->validated('target_track_id'),
                    $request->validated('reason') ?? '',
                    $staffUserId,
                    $staffUserId,
                    $customSteps
                );
            } catch (\InvalidArgumentException $e) {
                $code = $e->getCode() ?: 409;

                return response()->json(['message' => $e->getMessage()], (int) $code);
            }

            return response()->json([
                'session' => SessionResource::make($result['session'])->resolve(),
                'override' => $result['override'],
            ]);
        }

        // Predefined track (no custom path): staff can override without reason or PIN/QR/Request.
        if ($program && $request->filled('target_track_id') && ($customSteps === null || count($customSteps) === 0)) {
            try {
                $result = $this->sessionService->overrideByTrack(
                    $session,
                    (int) $request->validated('target_track_id'),
                    $request->validated('reason') ?? '',
                    $staffUserId,
                    $staffUserId,
                    $customSteps
                );
            } catch (\InvalidArgumentException $e) {
                $code = $e->getCode() ?: 409;

                return response()->json(['message' => $e->getMessage()], (int) $code);
            }

            return response()->json([
                'session' => SessionResource::make($result['session'])->resolve(),
                'override' => $result['override'],
            ]);
        }

        $result = $this->supervisorAuthService->verifyForAction(
            $request->validated(),
            $user,
            $session,
            'override'
        );
        if (! $result->isOk()) {
            return $this->respondSupervisorAuthFailure($result);
        }

        try {
            $result = $this->sessionService->overrideByTrack(
                $session,
                (int) $request->validated('target_track_id'),
                $request->validated('reason') ?? '',
                $result->authorizerUserId,
                $request->user()->id,
                $this->sanitizeCustomSteps($request->validated('custom_steps'))
            );
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode() ?: 409;
            if ($code === 401) {
                return response()->json(['message' => 'Invalid supervisor PIN.'], 401);
            }

            return response()->json(['message' => $e->getMessage()], (int) $code);
        }

        return response()->json([
            'session' => SessionResource::make($result['session'])->resolve(),
            'override' => $result['override'],
        ]);
    }

    private function user(): \App\Models\User
    {
        return request()->user();
    }

    /**
     * Sanitize custom_steps to array of ints or null.
     *
     * @return array<int>|null
     */
    private function sanitizeCustomSteps(mixed $value): ?array
    {
        if (! is_array($value) || count($value) === 0) {
            return null;
        }

        return array_values(array_map('intval', array_filter($value, fn ($v) => is_numeric($v))));
    }

    /**
     * Look up token by physical_id or qr_hash for triage entry. Returns physical_id, qr_hash, status.
     * Per site-scoping: when user has site_id, only tokens from that site are returned.
     * Per ISSUES-ELABORATION §11: logs each scan attempt to triage_scan_log (result not_found = potentially fabricated).
     */
    public function tokenLookup(Request $request): JsonResponse
    {
        $physicalId = $request->query('physical_id');
        $qrHash = $request->query('qr_hash');
        $siteId = $request->user()?->site_id;

        $token = $this->tokenService->lookupByPhysicalOrHash($physicalId, $qrHash, $siteId);

        $shouldLog = (is_string($physicalId) && $physicalId !== '') || (is_string($qrHash) && $qrHash !== '');

        if (! $token) {
            if (! $shouldLog) {
                return response()->json(['message' => 'physical_id or qr_hash is required.'], 422);
            }
            $this->triageScanLogService->log($request, null, 'not_found', null, null);
            return response()->json(['message' => 'Token not found.'], 404);
        }

        $this->triageScanLogService->log($request, $token->id, $token->status, $token->physical_id, $token->qr_code_hash);

        return response()->json([
            'physical_id' => $token->physical_id,
            'qr_hash' => $token->qr_code_hash,
            'status' => $token->status,
        ]);
    }
}
