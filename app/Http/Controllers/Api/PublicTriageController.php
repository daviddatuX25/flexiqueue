<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ClientAlreadyQueuedException;
use App\Exceptions\IdentityBindingException;
use App\Exceptions\TokenInUseException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientLookupByIdRequest;
use App\Http\Requests\BindSessionRequest;
use App\Models\IdentityRegistration;
use App\Models\Program;
use App\Models\Token;
use App\Services\ClientIdDocumentService;
use App\Services\SessionService;
use App\Services\TriageScanLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;
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

    private const ID_LOOKUP_THROTTLE = 'public_triage_id_lookup:';

    private const TOKEN_LOOKUP_MAX = 30;

    private const BIND_MAX = 20;

    public function __construct(
        private SessionService $sessionService,
        private TriageScanLogService $triageScanLogService,
        private ClientIdDocumentService $clientIdDocumentService,
    ) {}

    /**
     * Resolve program from program_id; return 403 if missing, inactive, or allow_public_triage false.
     */
    private function resolveProgramForPublicTriage(?int $programId): ?Program
    {
        if ($programId === null) {
            return null;
        }
        $program = Program::find($programId);
        if (! $program || ! $program->is_active || ! $program->settings()->getAllowPublicTriage()) {
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
        $program = $this->resolveProgramForPublicTriage($programId);
        if (! $program) {
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
     * POST /api/public/sessions/bind — program_id, qr_hash, track_id, optional client_category (default Regular).
     * Optional identity_registration_request (name?, birth_year?, client_category?) when ID not found; mutually exclusive with client_binding.
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

        $identityRegistrationRequest = $request->validated('identity_registration_request');

        if (is_array($identityRegistrationRequest) && ! empty($identityRegistrationRequest)) {
            $idNumberRaw = isset($identityRegistrationRequest['id_number']) ? trim((string) $identityRegistrationRequest['id_number']) : '';
            $idType = $identityRegistrationRequest['id_type'] ?? null;
            $idNumberEncrypted = null;
            $idNumberLast4 = null;
            if ($idNumberRaw !== '') {
                $idNumberEncrypted = Crypt::encryptString($idNumberRaw);
                $idNumberLast4 = $this->clientIdDocumentService->getLast4FromRawNumber($idNumberRaw);
            }

            $normalizedIdType = $idType !== '' && $idType !== null ? $idType : null;

            $registration = null;
            if ($idNumberRaw !== '' && $idNumberLast4 !== null) {
                $candidates = IdentityRegistration::query()
                    ->forProgram($program->id)
                    ->pending()
                    ->where('id_type', $normalizedIdType)
                    ->where('id_number_last4', $idNumberLast4)
                    ->whereNotNull('id_number_encrypted')
                    ->orderByDesc('id')
                    ->limit(10)
                    ->get();

                foreach ($candidates as $candidate) {
                    try {
                        $candidateRaw = Crypt::decryptString((string) $candidate->id_number_encrypted);
                        if (hash_equals($idNumberRaw, $candidateRaw)) {
                            $registration = $candidate;
                            break;
                        }
                    } catch (\Throwable) {
                        // Ignore decrypt errors and continue scanning candidates.
                    }
                }
            }

            if (! $registration) {
                $registration = IdentityRegistration::create([
                    'program_id' => $program->id,
                    'session_id' => null,
                    'name' => $identityRegistrationRequest['name'] ?? null,
                    'birth_year' => isset($identityRegistrationRequest['birth_year']) ? (int) $identityRegistrationRequest['birth_year'] : null,
                    'client_category' => $identityRegistrationRequest['client_category'] ?? null,
                    'id_type' => $normalizedIdType,
                    'id_number_encrypted' => $idNumberEncrypted,
                    'id_number_last4' => $idNumberLast4,
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

    /**
     * POST /api/public/clients/lookup-by-id — limited identity lookup for public triage.
     * No auth. Rate limited. Requires program_id in body. 403 when program_id missing/invalid or program does not allow public binding.
     *
     * Response shape mirrors staff /api/clients/lookup-by-id but never returns raw ID numbers:
     * - { match_status: 'not_found', client: null }
     * - {
     *     match_status: 'existing',
     *     client: { id, name, birth_year },
     *     id_document: { id, id_type, id_last4 }
     *   }
     */
    public function publicLookupById(ClientLookupByIdRequest $request): JsonResponse
    {
        $ipKey = self::ID_LOOKUP_THROTTLE.$request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, self::TOKEN_LOOKUP_MAX)) {
            return response()->json(['message' => 'Too many requests. Try again later.'], 429);
        }

        $programId = $request->validated('program_id');
        $program = $programId !== null ? Program::find((int) $programId) : null;
        if (! $program || ! $program->is_active || ! $program->settings()->allowsPublicBinding()) {
            return response()->json(['message' => 'Public identity binding is not available.'], 403);
        }

        $data = $request->validated();
        $idType = isset($data['id_type']) && $data['id_type'] !== '' && $data['id_type'] !== null
            ? $data['id_type']
            : null;
        $idNumber = $data['id_number'];

        if ($idType !== null) {
            $result = $this->clientIdDocumentService->lookupById($idType, $idNumber);
        } else {
            $result = $this->clientIdDocumentService->lookupByIdNumberOnly($idNumber);
            $result = [
                'client' => $result['client'],
                'id_document' => $result['id_document'],
                'match_status' => $result['match_status'],
                'id_types' => $result['id_types'] ?? [],
            ];
        }

        RateLimiter::hit($ipKey);

        if (isset($result['match_status']) && $result['match_status'] === 'ambiguous') {
            return response()->json([
                'match_status' => 'ambiguous',
                'message' => 'Can\'t auto-detect. Please select ID type first.',
                'id_types' => $result['id_types'],
            ]);
        }

        if (! $result['client'] || ! $result['id_document']) {
            return response()->json([
                'match_status' => 'not_found',
                'client' => null,
            ]);
        }

        $idLast4 = $this->clientIdDocumentService->getIdLast4FromDocument($result['id_document']);

        return response()->json([
            'match_status' => 'existing',
            'client' => [
                'id' => $result['client']->id,
                'name' => $result['client']->name,
                'birth_year' => $result['client']->birth_year,
            ],
            'id_document' => [
                'id' => $result['id_document']->id,
                'id_type' => $result['id_document']->id_type,
                'id_last4' => $idLast4,
            ],
        ]);
    }
}
