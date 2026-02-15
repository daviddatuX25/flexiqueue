<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\StepsRemainingException;
use App\Exceptions\TokenInUseException;
use App\Exceptions\TokenUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BindSessionRequest;
use App\Http\Requests\CallSessionRequest;
use App\Http\Requests\CancelSessionRequest;
use App\Http\Requests\ForceCompleteSessionRequest;
use App\Http\Requests\OverrideSessionRequest;
use App\Http\Requests\TransferSessionRequest;
use App\Models\Session;
use App\Models\Station;
use App\Models\User;
use App\Services\PinService;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use App\Support\ClientCategory;

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
     * When program requires permission and call would skip priority (FIFO calling regular before PWD), auth required.
     */
    public function call(CallSessionRequest $request, Session $session): JsonResponse
    {
        $user = $request->user();
        if ($this->callRequiresOverrideAuth($session) && ! $user->isAdmin() && ! $user->isSupervisorForAnyProgram()) {
            $validated = $request->validated();
            $authType = $validated['auth_type'] ?? null;
            if (! $authType || ! in_array($authType, ['preset_pin', 'preset_qr', 'temp_pin', 'temp_qr', 'pin', 'qr'], true)) {
                return response()->json([
                    'message' => 'Calling this client would skip priority clients. Supervisor authorization required.',
                ], 401);
            }
            $authType = $authType === 'pin' ? 'temp_pin' : ($authType === 'qr' ? 'temp_qr' : $authType);

            $staffUserId = $request->user()->id;
            if ($authType === 'preset_pin') {
                $key = self::PIN_FAIL_THROTTLE_PREFIX.$staffUserId;
                if (RateLimiter::tooManyAttempts($key, self::PIN_FAIL_MAX_ATTEMPTS)) {
                    return response()->json(['message' => 'Too many attempts. Try again in 15 minutes.'], 429);
                }
            }

            $verified = match ($authType) {
                'temp_pin' => $this->pinService->validateTemporaryPin($validated['temp_code'] ?? ''),
                'temp_qr' => $this->pinService->validateTemporaryQr($validated['qr_scan_token'] ?? ''),
                'preset_qr' => $this->pinService->validatePresetQr($validated['qr_scan_token'] ?? ''),
                default => $this->pinService->validate((int) ($validated['supervisor_user_id'] ?? 0), $validated['supervisor_pin'] ?? ''),
            };

            if (! $verified) {
                if ($authType === 'preset_pin') {
                    RateLimiter::hit(self::PIN_FAIL_THROTTLE_PREFIX.$staffUserId, self::PIN_FAIL_DECAY_MINUTES * 60);
                }
                $message = in_array($authType, ['temp_pin', 'temp_qr'], true)
                    ? 'Authorization expired. Request a new one.'
                    : 'Invalid supervisor PIN.';

                return response()->json(['message' => $message], 401);
            }

            if ($authType === 'preset_pin') {
                RateLimiter::clear(self::PIN_FAIL_THROTTLE_PREFIX.$staffUserId);
            }

            if (in_array($authType, ['preset_pin', 'preset_qr'], true)) {
                $authorizer = User::find($verified['user_id']);
                if (! $authorizer || ! ($authorizer->isAdmin() || $authorizer->isSupervisorForProgram($session->program_id))) {
                    return response()->json(['message' => 'You are not a supervisor for this program. Preset authorization cannot be used here.'], 403);
                }
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
     * Whether calling this session would require supervisor auth (skipping priority when require_permission_before_override).
     */
    private function callRequiresOverrideAuth(Session $session): bool
    {
        if ($session->status !== 'waiting') {
            return false;
        }

        $station = Station::find($session->current_station_id);
        if (! $station) {
            return false;
        }

        $station->loadMissing('program');
        $program = $station->program;
        if (! $program || ! $program->getRequirePermissionBeforeOverride()) {
            return false;
        }

        $priorityFirst = $station->priority_first_override !== null
            ? (bool) $station->priority_first_override
            : $program->getPriorityFirst();
        if ($priorityFirst) {
            return false;
        }

        if (ClientCategory::isPriority($session->client_category)) {
            return false;
        }

        $priorityWaitingCount = Session::query()
            ->where('current_station_id', $station->id)
            ->where('status', 'waiting')
            ->where('id', '!=', $session->id)
            ->get()
            ->filter(fn (Session $s) => $s->isPriorityCategory())
            ->count();

        return $priorityWaitingCount > 0;
    }

    /**
     * Infer auth_type from request when not explicitly sent (e.g. legacy tests send supervisor_user_id + supervisor_pin).
     */
    private function resolveAuthType(array $validated): ?string
    {
        $authType = $validated['auth_type'] ?? null;
        if ($authType && in_array($authType, ['preset_pin', 'preset_qr', 'temp_pin', 'temp_qr', 'pin', 'qr'], true)) {
            return $authType === 'pin' ? 'temp_pin' : ($authType === 'qr' ? 'temp_qr' : $authType);
        }
        if (! empty($validated['supervisor_user_id']) && ! empty($validated['supervisor_pin'])) {
            return 'preset_pin';
        }
        if (! empty($validated['temp_code'])) {
            return 'temp_pin';
        }
        if (! empty($validated['qr_scan_token'])) {
            return 'temp_qr';
        }

        return null;
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
        $user = $request->user();
        $staffUserId = $user->id;

        if ($user->isAdmin() || $user->isSupervisorForAnyProgram()) {
            try {
                $result = $this->sessionService->forceComplete(
                    $session,
                    $request->validated('reason'),
                    $staffUserId,
                    $staffUserId
                );
            } catch (\InvalidArgumentException $e) {
                $code = $e->getCode() ?: 409;
                return response()->json(['message' => $e->getMessage()], (int) $code);
            }

            return response()->json([
                'session' => $this->formatSession($result['session']),
                'token' => $result['token'],
            ]);
        }

        $validated = $request->validated();
        $authType = $this->resolveAuthType($validated);
        if (! $authType || ! in_array($authType, ['preset_pin', 'preset_qr', 'temp_pin', 'temp_qr'], true)) {
            return response()->json(['message' => 'Supervisor authorization required.'], 401);
        }

        if ($authType === 'preset_pin') {
            $key = self::PIN_FAIL_THROTTLE_PREFIX.$staffUserId;
            if (RateLimiter::tooManyAttempts($key, self::PIN_FAIL_MAX_ATTEMPTS)) {
                return response()->json(['message' => 'Too many attempts. Try again in 15 minutes.'], 429);
            }
        }

        $verified = match ($authType) {
            'temp_pin' => $this->pinService->validateTemporaryPin($validated['temp_code'] ?? ''),
            'temp_qr' => $this->pinService->validateTemporaryQr($validated['qr_scan_token'] ?? ''),
            'preset_qr' => $this->pinService->validatePresetQr($validated['qr_scan_token'] ?? ''),
            default => $this->pinService->validate((int) ($validated['supervisor_user_id'] ?? 0), $validated['supervisor_pin'] ?? ''),
        };

        if (! $verified) {
            if ($authType === 'preset_pin') {
                RateLimiter::hit(self::PIN_FAIL_THROTTLE_PREFIX.$staffUserId, self::PIN_FAIL_DECAY_MINUTES * 60);
            }
            $message = in_array($authType, ['temp_pin', 'temp_qr'], true)
                ? 'Authorization expired. Request a new one.'
                : 'Invalid supervisor PIN.';
            return response()->json(['message' => $message], 401);
        }

        if ($authType === 'preset_pin') {
            RateLimiter::clear(self::PIN_FAIL_THROTTLE_PREFIX.$staffUserId);
        }

        if (in_array($authType, ['preset_pin', 'preset_qr'], true)) {
            $authorizer = User::find($verified['user_id']);
            if (! $authorizer || ! ($authorizer->isAdmin() || $authorizer->isSupervisorForProgram($session->program_id))) {
                return response()->json(['message' => 'You are not a supervisor for this program. Preset authorization cannot be used here.'], 403);
            }
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
        $user = $request->user();
        $staffUserId = $user->id;

        if ($user->isAdmin() || $user->isSupervisorForAnyProgram()) {
            try {
                $result = $this->sessionService->overrideByTrack(
                    $session,
                    (int) $request->validated('target_track_id'),
                    $request->validated('reason'),
                    $staffUserId,
                    $staffUserId,
                    $this->sanitizeCustomSteps($request->validated('custom_steps'))
                );
            } catch (\InvalidArgumentException $e) {
                $code = $e->getCode() ?: 409;
                return response()->json(['message' => $e->getMessage()], (int) $code);
            }

            return response()->json([
                'session' => $this->formatSession($result['session']),
                'override' => $result['override'] ?? null,
            ]);
        }

        $validated = $request->validated();
        $authType = $this->resolveAuthType($validated);
        if (! $authType || ! in_array($authType, ['preset_pin', 'preset_qr', 'temp_pin', 'temp_qr'], true)) {
            return response()->json(['message' => 'Supervisor authorization required.'], 401);
        }

        if ($authType === 'preset_pin') {
            $key = self::PIN_FAIL_THROTTLE_PREFIX.$staffUserId;
            if (RateLimiter::tooManyAttempts($key, self::PIN_FAIL_MAX_ATTEMPTS)) {
                return response()->json(['message' => 'Too many attempts. Try again in 15 minutes.'], 429);
            }
        }

        $verified = match ($authType) {
            'temp_pin' => $this->pinService->validateTemporaryPin($validated['temp_code'] ?? ''),
            'temp_qr' => $this->pinService->validateTemporaryQr($validated['qr_scan_token'] ?? ''),
            'preset_qr' => $this->pinService->validatePresetQr($validated['qr_scan_token'] ?? ''),
            default => $this->pinService->validate((int) ($validated['supervisor_user_id'] ?? 0), $validated['supervisor_pin'] ?? ''),
        };

        if (! $verified) {
            if ($authType === 'preset_pin') {
                RateLimiter::hit(self::PIN_FAIL_THROTTLE_PREFIX.$staffUserId, self::PIN_FAIL_DECAY_MINUTES * 60);
            }
            $message = in_array($authType, ['temp_pin', 'temp_qr'], true)
                ? 'Authorization expired. Request a new one.'
                : 'Invalid supervisor PIN.';
            return response()->json(['message' => $message], 401);
        }

        if ($authType === 'preset_pin') {
            RateLimiter::clear(self::PIN_FAIL_THROTTLE_PREFIX.$staffUserId);
        }

        if (in_array($authType, ['preset_pin', 'preset_qr'], true)) {
            $authorizer = User::find($verified['user_id']);
            if (! $authorizer || ! ($authorizer->isAdmin() || $authorizer->isSupervisorForProgram($session->program_id))) {
                return response()->json(['message' => 'You are not a supervisor for this program. Preset authorization cannot be used here.'], 403);
            }
        }

        try {
            $result = $this->sessionService->overrideByTrack(
                $session,
                (int) $request->validated('target_track_id'),
                $request->validated('reason'),
                $verified['user_id'],
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
