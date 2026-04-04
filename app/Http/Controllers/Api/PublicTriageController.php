<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ClientAlreadyQueuedException;
use App\Exceptions\IdentityBindingException;
use App\Exceptions\TokenInUseException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BindSessionRequest;
use App\Http\Requests\PublicVerifyIdentityRequest;
use App\Models\Client;
use App\Models\IdentityRegistration;
use App\Models\Program;
use App\Models\Site;
use App\Services\MobileCryptoService;
use App\Services\SessionService;
use App\Services\TokenService;
use App\Services\TriageScanLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public triage API: token lookup and bind when program allows public self-serve.
 * No auth. Rate limited. Bind/verify require kiosk self-service; token lookup requires any kiosk feature enabled.
 */
class PublicTriageController extends Controller
{
    private const TOKEN_LOOKUP_THROTTLE = 'public_triage_lookup:';

    private const BIND_THROTTLE = 'public_triage_bind:';

    private const VERIFY_IDENTITY_THROTTLE = 'public_verify_identity:';

    private const TOKEN_LOOKUP_MAX = 30;

    private const BIND_MAX = 20;

    private const VERIFY_IDENTITY_MAX = 20;

    public function __construct(
        private SessionService $sessionService,
        private TriageScanLogService $triageScanLogService,
        private MobileCryptoService $mobileCrypto,
        private TokenService $tokenService,
    ) {}

    /**
     * Resolve program from program_id. Site from program. No publish filter; access controlled by device auth (PIN/QR) on display/triage.
     */
    private function resolveProgramForPublicTriage(?int $programId): ?Program
    {
        if ($programId === null) {
            return null;
        }
        $program = Program::with('site')->find($programId);
        if (! $program || ! $program->is_active || ! $program->settings()->getKioskSelfServiceTriageEnabled()) {
            return null;
        }
        if (! $program->site) {
            return null;
        }

        return $program;
    }

    /**
     * Token lookup is allowed when the kiosk surface is enabled (self-service and/or status checker).
     */
    private function resolveProgramForPublicTokenLookup(?int $programId): ?Program
    {
        if ($programId === null) {
            return null;
        }
        $program = Program::with('site')->find($programId);
        if (! $program || ! $program->is_active || ! $program->settings()->getKioskSurfaceEnabled()) {
            return null;
        }
        if (! $program->site) {
            return null;
        }

        return $program;
    }

    /**
     * GET /api/public/token-lookup?qr_hash=...&program_id=... or ?physical_id=...&program_id=...
     * Returns { physical_id, qr_hash, status }. 403 when program_id missing/invalid or public triage disabled.
     */
    public function tokenLookup(Request $request): JsonResponse
    {
        $key = self::TOKEN_LOOKUP_THROTTLE.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::TOKEN_LOOKUP_MAX)) {
            return response()->json(['message' => 'Too many requests. Try again later.'], 429);
        }

        $programId = $request->query('program_id') !== null ? (int) $request->query('program_id') : null;
        $program = $this->resolveProgramForPublicTokenLookup($programId);
        if (! $program) {
            return response()->json(['message' => 'Kiosk is not available for this program.'], 403);
        }

        $physicalId = $request->query('physical_id');
        $qrHash = $request->query('qr_hash');
        $siteId = $program->site_id;

        $token = $this->tokenService->lookupByPhysicalOrHash($physicalId, $qrHash, $siteId);

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
     * POST /api/public/verify-identity — FLOW A: exact-match verification; creates bind_confirmation hold for staff.
     * Per IDENTITY-BINDING-FINAL-IMPLEMENTATION-PLAN §3.2: no auth, rate-limited. Always 200 (never 404).
     */
    public function verifyIdentity(PublicVerifyIdentityRequest $request): JsonResponse
    {
        $key = self::VERIFY_IDENTITY_THROTTLE.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::VERIFY_IDENTITY_MAX)) {
            return response()->json(['message' => 'Too many requests. Try again later.'], 429);
        }

        $program = $this->resolveProgramForPublicTriage((int) $request->validated('program_id'));
        if (! $program) {
            return response()->json(['message' => 'Public verification is not available.'], 403);
        }
        if ($program->settings()->getAllowUnverifiedEntry()) {
            return response()->json(['message' => 'Verification not required for this program.'], 403);
        }

        $siteId = $program->site_id;
        $token = $request->filled('token_id')
            ? $this->tokenService->lookupById((int) $request->validated('token_id'), $siteId)
            : $this->tokenService->lookupByPhysicalOrHash(null, $request->validated('qr_hash'), $siteId);
        if (! $token) {
            return response()->json(['verified' => false, 'message' => 'No matching account found.'], 200);
        }

        $trackId = (int) $request->validated('track_id');
        $track = $program->serviceTracks()->find($trackId);
        if (! $track) {
            return response()->json(['verified' => false, 'message' => 'No matching account found.'], 200);
        }

        $mobileHash = $this->mobileCrypto->hash(trim((string) $request->validated('mobile')));
        $firstName = trim((string) $request->validated('first_name'));
        $lastName = trim((string) $request->validated('last_name'));
        $birthDate = $request->validated('birth_date');

        $matches = Client::query()
            ->where('site_id', $program->site_id)
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [mb_strtolower($firstName)])
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [mb_strtolower($lastName)])
            ->whereDate('birth_date', $birthDate)
            ->where('mobile_hash', $mobileHash)
            ->get();

        if ($matches->count() !== 1) {
            RateLimiter::hit($key);

            return response()->json(['verified' => false, 'message' => 'No matching account found.'], 200);
        }

        $client = $matches->first();

        $existing = IdentityRegistration::query()
            ->where('client_id', $client->id)
            ->where('token_id', $token->id)
            ->where('request_type', 'bind_confirmation')
            ->pending()
            ->first();

        if ($existing) {
            RateLimiter::hit($key);

            return response()->json([
                'status' => 'pending',
                'message' => 'A verification request is already pending. Staff will process it shortly.',
            ], 200);
        }

        IdentityRegistration::create([
            'program_id' => $program->id,
            'request_type' => 'bind_confirmation',
            'session_id' => null,
            'token_id' => $token->id,
            'track_id' => $track->id,
            'client_id' => $client->id,
            'first_name' => $client->first_name,
            'middle_name' => $client->middle_name,
            'last_name' => $client->last_name,
            'birth_date' => $client->birth_date,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        RateLimiter::hit($key);

        return response()->json([
            'status' => 'pending',
            'message' => 'Your identity has been verified. Please see a staff member to complete your visit.',
        ], 200);
    }

    /**
     * POST /api/public/sessions/bind — program_id, qr_hash, track_id, optional client_category (default Regular).
     * Optional identity_registration_request (first_name?, middle_name?, last_name?, birth_date?, address?, client_category?) when ID not found; mutually exclusive with client_binding.
     * 403 when program_id missing/invalid or public triage disabled. 201 on success. When identity_registration_request and allow_unverified_entry false, returns request_submitted (no session).
     */
    public function bind(BindSessionRequest $request): JsonResponse
    {
        $key = self::BIND_THROTTLE.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::BIND_MAX)) {
            return response()->json(['message' => 'Too many requests. Try again later.'], 429);
        }

        $programId = $request->validated('program_id');
        $program = $programId !== null ? $this->resolveProgramForPublicTriage((int) $programId) : null;
        if (! $program) {
            return response()->json(['message' => 'Public self-serve triage is not available.'], 403);
        }

        // Per IDENTITY-BINDING-FINAL-IMPLEMENTATION-PLAN §3.1: plain bind not available when allow_unverified false.
        if (! $program->settings()->getAllowUnverifiedEntry() && ! $request->filled('identity_registration_request')) {
            RateLimiter::hit($key);

            return response()->json([
                'message' => 'Token binding is not available. Please verify your identity or submit a registration for staff to process.',
            ], 403);
        }

        // Per plan §3.5: token hold guard — do not create session/hold if token already has pending verification.
        if ($request->filled('qr_hash')) {
            $token = $this->tokenService->lookupByPhysicalOrHash(null, $request->input('qr_hash'), $program->site_id);
            if ($token && IdentityRegistration::where('token_id', $token->id)->pending()->exists()) {
                RateLimiter::hit($key);

                return response()->json([
                    'message' => 'This token already has a pending verification. Please see a staff member.',
                ], 409);
            }
        }

        $identityRegistrationRequest = $request->validated('identity_registration_request');

        if (is_array($identityRegistrationRequest) && ! empty($identityRegistrationRequest)) {
            $mobileRaw = isset($identityRegistrationRequest['mobile']) ? trim((string) $identityRegistrationRequest['mobile']) : '';
            $mobileEncrypted = null;
            $mobileHash = null;
            if ($mobileRaw !== '') {
                $mobileEncrypted = $this->mobileCrypto->encrypt($mobileRaw);
                $mobileHash = $this->mobileCrypto->hash($mobileRaw);
            }

            // If mobile already belongs to an existing client, allow session but do not create a new pending registration
            // (registration part is "rejected" — staff will not see a duplicate to accept).
            $existingClient = $mobileHash !== null
                ? Client::where('mobile_hash', $mobileHash)->where('site_id', $program->site_id)->first()
                : null;

            if ($existingClient !== null) {
                if (! $program->settings()->getAllowUnverifiedEntry() || ! $request->filled('qr_hash')) {
                    RateLimiter::hit($key);

                    return response()->json([
                        'request_submitted' => false,
                        'message' => 'This phone number is already registered. Use your token to start your visit — no new registration needed.',
                    ], 200);
                }
                $qrHash = $request->validated('qr_hash');
                $trackId = (int) $request->validated('track_id');
                $clientCategory = $identityRegistrationRequest['client_category'] ?? 'Regular';
                try {
                    $result = $this->sessionService->bind(
                        $qrHash,
                        $trackId,
                        $clientCategory,
                        null,
                        ['client_id' => $existingClient->id, 'source' => 'phone_match'],
                        'public_triage',
                        null,
                        $program->id
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
                } catch (ClientAlreadyQueuedException $e) {
                    $s = $e->activeSession;
                    $s->load('currentStation');

                    return response()->json([
                        'message' => 'You already have an active visit in the queue.',
                        'error_code' => 'client_already_queued',
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
                    'client_already_registered' => true,
                    'unverified' => false,
                ], 201);
            }

            $registration = null;
            if ($mobileHash !== null) {
                $registration = IdentityRegistration::query()
                    ->forProgram($program->id)
                    ->pending()
                    ->where('mobile_hash', $mobileHash)
                    ->first();
            }

            if (! $registration) {
                $tokenForReg = $request->filled('qr_hash')
                    ? $this->tokenService->lookupByPhysicalOrHash(null, $request->input('qr_hash'), $program->site_id)
                    : null;
                $trackId = $request->filled('track_id') ? (int) $request->validated('track_id') : null;
                $registration = IdentityRegistration::create([
                    'program_id' => $program->id,
                    'request_type' => 'registration',
                    'session_id' => null,
                    'token_id' => $tokenForReg?->id,
                    'track_id' => $trackId,
                    'first_name' => $identityRegistrationRequest['first_name'] ?? null,
                    'middle_name' => $identityRegistrationRequest['middle_name'] ?? null,
                    'last_name' => $identityRegistrationRequest['last_name'] ?? null,
                    'birth_date' => isset($identityRegistrationRequest['birth_date']) ? $identityRegistrationRequest['birth_date'] : null,
                    'address_line_1' => $identityRegistrationRequest['address_line_1'] ?? null,
                    'address_line_2' => $identityRegistrationRequest['address_line_2'] ?? null,
                    'city' => $identityRegistrationRequest['city'] ?? null,
                    'state' => $identityRegistrationRequest['state'] ?? null,
                    'postal_code' => $identityRegistrationRequest['postal_code'] ?? null,
                    'country' => $identityRegistrationRequest['country'] ?? null,
                    'client_category' => $identityRegistrationRequest['client_category'] ?? null,
                    'mobile_encrypted' => $mobileEncrypted,
                    'mobile_hash' => $mobileHash,
                    'status' => 'pending',
                    'requested_at' => now(),
                ]);
            }

            // If unverified entry is not allowed, or if no token was provided, we only submit the request.
            // This supports a "no token yet" registration request flow.
            if (! $program->settings()->getAllowUnverifiedEntry() || ! $request->filled('qr_hash')) {
                RateLimiter::hit($key);

                return response()->json([
                    'request_submitted' => true,
                    'message' => 'Your request has been submitted. Staff will verify your identity. You can try again after verification.',
                ], 200);
            }

            $qrHash = $request->validated('qr_hash');
            $trackId = (int) $request->validated('track_id');
            $clientCategory = $registration->client_category ?? 'Regular';

            try {
                $result = $this->sessionService->bind(
                    $qrHash,
                    $trackId,
                    $clientCategory,
                    null,
                    null,
                    'public_triage',
                    $registration->id,
                    $program->id
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
            } catch (ClientAlreadyQueuedException $e) {
                $s = $e->activeSession;
                $s->load('currentStation');

                return response()->json([
                    'message' => 'You already have an active visit in the queue.',
                    'error_code' => 'client_already_queued',
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
                'unverified' => true,
            ], 201);
        }

        $qrHash = $request->validated('qr_hash');
        $trackId = (int) $request->validated('track_id');
        $clientCategory = $request->validated('client_category') ?? 'Regular';

        try {
            $result = $this->sessionService->bind(
                $qrHash,
                $trackId,
                $clientCategory,
                null,
                $request->validated('client_binding'),
                null,
                null,
                $program->id
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
        } catch (ClientAlreadyQueuedException $e) {
            $s = $e->activeSession;
            $s->load('currentStation');

            return response()->json([
                'message' => 'You already have an active visit in the queue.',
                'error_code' => 'client_already_queued',
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
