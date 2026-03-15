<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\StationPageController;
use App\Exceptions\ClientAlreadyQueuedException;
use App\Exceptions\TokenInUseException;
use App\Models\IdentityRegistration;
use App\Models\Program;
use App\Services\ClientService;
use App\Services\MobileCryptoService;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Staff triage: list pending identity registrations, accept (link to client + optional register ID), reject.
 * Direct: staff-only create of an already-accepted registration (no pending step).
 */
class IdentityRegistrationController extends Controller
{
    public function __construct(
        private ClientService $clientService,
        private MobileCryptoService $mobileCrypto,
        private SessionService $sessionService,
    ) {}

    /**
     * GET /api/identity-registrations — list pending for staff's station program.
     * Per A.2.2: program from user.assigned_station_id → station.program_id.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $program = $user->assignedStation?->program;

        // Per central-edge follow-up: admin/supervisor with no assigned station uses session-selected program context.
        if (! $program) {
            if (! $user->isAdmin() && ! $user->isSupervisorForAnyProgram()) {
                return response()->json(['message' => 'No station assigned.'], 422);
            }

            $programId = $request->session()->get(StationPageController::SESSION_KEY_PROGRAM_ID);
            $program = $programId ? Program::query()->where('id', (int) $programId)->where('is_active', true)->first() : null;
            if (! $program) {
                return response()->json(['message' => 'Program not selected or inactive.'], 422);
            }
            if (! $user->isAdmin() && ! $user->isSupervisorForProgram($program->id)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        $registrations = IdentityRegistration::query()
            ->forProgram($program->id)
            ->pending()
            ->with(['session.token', 'idVerifiedBy', 'token', 'track', 'client'])
            ->orderByDesc('requested_at')
            ->get();

        $data = $registrations->map(function (IdentityRegistration $r) {
            $mobileMasked = $r->mobile_encrypted
                ? $this->mobileCrypto->mask($this->mobileCrypto->decrypt($r->mobile_encrypted))
                : null;

            $payload = [
                'id' => $r->id,
                'request_type' => $r->request_type ?? 'registration',
                'first_name' => $r->first_name,
                'middle_name' => $r->middle_name,
                'last_name' => $r->last_name,
                'birth_date' => $r->birth_date?->format('Y-m-d'),
                'address_line_1' => $r->address_line_1,
                'address_line_2' => $r->address_line_2,
                'city' => $r->city,
                'state' => $r->state,
                'postal_code' => $r->postal_code,
                'country' => $r->country,
                'client_category' => $r->client_category,
                'mobile_masked' => $mobileMasked,
                'id_verified' => (bool) $r->id_verified,
                'id_verified_at' => $r->id_verified_at?->toIso8601String(),
                'id_verified_by_user_id' => $r->id_verified_by_user_id,
                'id_verified_by' => $r->idVerifiedBy?->name,
                'requested_at' => $r->requested_at?->toIso8601String(),
                'session_id' => $r->session_id,
                'session_alias' => $r->session?->token?->physical_id ?? null,
            ];
            if ($r->request_type === 'bind_confirmation') {
                $payload['token_id'] = $r->token_id;
                $payload['token_physical_id'] = $r->token?->physical_id;
                $payload['track_id'] = $r->track_id;
                $payload['track_name'] = $r->track?->name;
                $payload['client_id'] = $r->client_id;
                $payload['client_name'] = $r->client?->display_name ?? null;
            }

            return $payload;
        })->values()->all();

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/identity-registrations/direct — staff creates an identity registration that is already accepted.
     * No pending step; no queue session. Requires first_name, last_name, birth_date, client_category.
     */
    public function direct(Request $request): JsonResponse
    {
        $request->validate([
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date'],
            'client_category' => ['required', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $program = $user->assignedStation?->program;

        // Per central-edge follow-up: admin/supervisor with no assigned station uses request program_id then session-selected program context.
        if (! $program) {
            if (! $user->isAdmin() && ! $user->isSupervisorForAnyProgram()) {
                return response()->json(['message' => 'No station assigned.'], 422);
            }

            $programId = $request->input('program_id');
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
        }

        $firstName = $request->string('first_name')->trim();
        $middleName = $request->filled('middle_name') ? $request->string('middle_name')->trim() : null;
        $lastName = $request->string('last_name')->trim();
        $birthDate = $request->input('birth_date');
        $clientCategory = $request->string('client_category')->trim();
        $mobileRaw = $request->input('mobile');
        $mobile = is_string($mobileRaw) ? trim($mobileRaw) : '';
        $mobileEncrypted = null;
        $mobileHash = null;
        if ($mobile !== '') {
            $mobileEncrypted = $this->mobileCrypto->encrypt($mobile);
            $mobileHash = $this->mobileCrypto->hash($mobile);
        }

        $address = array_filter([
            'address_line_1' => $request->input('address_line_1'),
            'address_line_2' => $request->input('address_line_2'),
            'city' => $request->input('city'),
            'state' => $request->input('state'),
            'postal_code' => $request->input('postal_code'),
            'country' => $request->input('country'),
        ], fn ($v) => $v !== null && $v !== '');

        DB::transaction(function () use ($program, $request, $firstName, $middleName, $lastName, $birthDate, $clientCategory, $mobile, $mobileEncrypted, $mobileHash, $address) {
            $client = $this->clientService->createClient(
                $firstName,
                $lastName,
                $birthDate,
                $program->site_id,
                $mobile !== '' ? $mobile : null,
                $middleName,
                $address ?: null,
            );

            IdentityRegistration::create([
                'program_id' => $program->id,
                'request_type' => 'registration',
                'session_id' => null,
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'birth_date' => $birthDate,
                'address_line_1' => $address['address_line_1'] ?? null,
                'address_line_2' => $address['address_line_2'] ?? null,
                'city' => $address['city'] ?? null,
                'state' => $address['state'] ?? null,
                'postal_code' => $address['postal_code'] ?? null,
                'country' => $address['country'] ?? null,
                'client_category' => $clientCategory,
                'client_id' => $client->id,
                'mobile_encrypted' => $mobileEncrypted,
                'mobile_hash' => $mobileHash,
                'id_verified' => false,
                'status' => 'accepted',
                'requested_at' => now(),
                'resolved_at' => now(),
                'resolved_by_user_id' => $request->user()->id,
            ]);
        });

        return response()->json(['message' => 'Registration created.']);
    }

    /**
     * GET /api/identity-registrations/{id}/possible-matches — clients matching first/middle/last name + birth_date for accept flow.
     */
    public function possibleMatches(Request $request, IdentityRegistration $identityRegistration): JsonResponse
    {
        $user = $request->user();
        $program = $user->assignedStation?->program;

        if (! $program) {
            if (! $user->isAdmin() && ! $user->isSupervisorForAnyProgram()) {
                return response()->json(['message' => 'No station assigned.'], 422);
            }
            $program = Program::query()->where('id', (int) $identityRegistration->program_id)->where('is_active', true)->first();
            if (! $program) {
                return response()->json(['message' => 'Program not selected or inactive.'], 422);
            }
            if (! $user->isAdmin() && ! $user->isSupervisorForProgram($program->id)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }
        if ($identityRegistration->program_id !== $program->id || $identityRegistration->status !== 'pending') {
            return response()->json(['data' => []]);
        }

        $searchName = trim(implode(' ', array_filter([
            $identityRegistration->first_name ?? '',
            $identityRegistration->middle_name ?? '',
            $identityRegistration->last_name ?? '',
        ])));
        $birthDate = $identityRegistration->birth_date?->format('Y-m-d');

        if ($searchName === '') {
            return response()->json(['data' => []]);
        }

        $params = [
            'name' => $searchName,
            'birth_date' => $birthDate,
            'per_page' => 10,
            'page' => 1,
            'site_id' => $program->site_id,
        ];
        $paginator = $this->clientService->searchClients($params);

        $data = $paginator->map(fn ($client) => [
            'id' => $client->id,
            'first_name' => $client->first_name,
            'middle_name' => $client->middle_name,
            'last_name' => $client->last_name,
            'birth_date' => $client->birth_date?->format('Y-m-d'),
        ])->values()->all();

        $existingClientByPhone = null;
        if ($identityRegistration->mobile_hash !== null) {
            $clientByPhone = \App\Models\Client::where('mobile_hash', $identityRegistration->mobile_hash)
                ->where('site_id', $program->site_id)
                ->first();
            if ($clientByPhone !== null) {
                $existingClientByPhone = [
                    'id' => $clientByPhone->id,
                    'first_name' => $clientByPhone->first_name,
                    'middle_name' => $clientByPhone->middle_name,
                    'last_name' => $clientByPhone->last_name,
                    'birth_date' => $clientByPhone->birth_date?->format('Y-m-d'),
                ];
            }
        }

        return response()->json([
            'data' => $data,
            'existing_client_by_phone' => $existingClientByPhone,
        ]);
    }

    /**
     * POST /api/identity-registrations/{id}/accept — verify first_name, last_name, birth_date, client_category; link to existing or create client.
     */
    public function accept(Request $request, IdentityRegistration $identityRegistration): JsonResponse
    {
        $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date'],
            'client_category' => ['required', 'string', 'max:50'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'create_new_client' => ['nullable', 'boolean'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $program = $user->assignedStation?->program;

        if (! $program) {
            if (! $user->isAdmin() && ! $user->isSupervisorForAnyProgram()) {
                return response()->json(['message' => 'No station assigned.'], 422);
            }
            $program = Program::query()->where('id', (int) $identityRegistration->program_id)->where('is_active', true)->first();
            if (! $program) {
                return response()->json(['message' => 'Program not selected or inactive.'], 422);
            }
            if (! $user->isAdmin() && ! $user->isSupervisorForProgram($program->id)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }
        if ($identityRegistration->program_id !== $program->id || $identityRegistration->status !== 'pending') {
            return response()->json(['message' => 'Registration not found or already resolved.'], 404);
        }

        $createNew = $request->boolean('create_new_client');
        $clientId = $request->integer('client_id');
        if (! $createNew && ! $clientId) {
            return response()->json(['message' => 'Provide client_id or create_new_client.'], 422);
        }

        DB::transaction(function () use ($identityRegistration, $request, $program, $createNew, $clientId) {
                $firstName = $request->string('first_name')->trim();
                $middleName = $request->filled('middle_name') ? $request->string('middle_name')->trim() : null;
                $lastName = $request->string('last_name')->trim();
                $birthDate = $request->input('birth_date');
                $clientCategory = $request->string('client_category')->trim();

                $address = array_filter([
                    'address_line_1' => $request->input('address_line_1'),
                    'address_line_2' => $request->input('address_line_2'),
                    'city' => $request->input('city'),
                    'state' => $request->input('state'),
                    'postal_code' => $request->input('postal_code'),
                    'country' => $request->input('country'),
                ], fn ($v) => $v !== null && $v !== '');

                if ($createNew) {
                    $client = $this->clientService->createClient(
                        $firstName,
                        $lastName,
                        $birthDate,
                        $program->site_id,
                        null,
                        $middleName,
                        $address ?: null,
                    );
                    $clientId = $client->id;
                } else {
                    $client = \App\Models\Client::findOrFail($clientId);
                    if ($client->site_id !== $program->site_id) {
                        throw new \Illuminate\Http\Exceptions\HttpResponseException(
                            response()->json(['message' => 'Client does not belong to this program\'s site.'], 404)
                        );
                    }
                    $client->update([
                        'first_name' => $firstName,
                        'middle_name' => $middleName,
                        'last_name' => $lastName,
                        'birth_date' => $birthDate,
                        'address_line_1' => $address['address_line_1'] ?? $client->address_line_1,
                        'address_line_2' => $address['address_line_2'] ?? $client->address_line_2,
                        'city' => $address['city'] ?? $client->city,
                        'state' => $address['state'] ?? $client->state,
                        'postal_code' => $address['postal_code'] ?? $client->postal_code,
                        'country' => $address['country'] ?? $client->country,
                    ]);
                }

                $identityRegistration->update([
                    'status' => 'accepted',
                    'client_id' => $clientId,
                    'first_name' => $firstName,
                    'middle_name' => $middleName,
                    'last_name' => $lastName,
                    'birth_date' => $birthDate,
                    'address_line_1' => $address['address_line_1'] ?? null,
                    'address_line_2' => $address['address_line_2'] ?? null,
                    'city' => $address['city'] ?? null,
                    'state' => $address['state'] ?? null,
                    'postal_code' => $address['postal_code'] ?? null,
                    'country' => $address['country'] ?? null,
                    'client_category' => $clientCategory,
                    'resolved_at' => now(),
                    'resolved_by_user_id' => $request->user()->id,
                ]);

                if ($identityRegistration->session_id) {
                    $identityRegistration->session->update([
                        'client_id' => $clientId,
                        'client_category' => $clientCategory,
                    ]);
                }
            });

        return response()->json(['message' => 'Registration accepted.']);
    }

    /**
     * POST /api/identity-registrations/{id}/confirm-bind — FLOW A: staff confirms bind_confirmation hold, creates session.
     * Per IDENTITY-BINDING-FINAL-IMPLEMENTATION-PLAN §3.3: token-in-use check then SessionService::bind.
     */
    public function confirmBind(Request $request, IdentityRegistration $identityRegistration): JsonResponse
    {
        $identityRegistration->load(['token', 'track', 'program', 'client']);
        $user = $request->user();
        $program = $user->assignedStation?->program;

        if (! $program) {
            if (! $user->isAdmin() && ! $user->isSupervisorForAnyProgram()) {
                return response()->json(['message' => 'No station assigned.'], 422);
            }
            $program = Program::query()->where('id', (int) $identityRegistration->program_id)->where('is_active', true)->first();
            if (! $program) {
                return response()->json(['message' => 'Program not selected or inactive.'], 422);
            }
            if (! $user->isAdmin() && ! $user->isSupervisorForProgram($program->id)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }
        if ($identityRegistration->program_id !== $program->id) {
            return response()->json(['message' => 'Registration does not belong to this program.'], 422);
        }
        if ($identityRegistration->request_type !== 'bind_confirmation' || $identityRegistration->status !== 'pending') {
            return response()->json(['message' => 'This registration cannot be confirmed for bind.'], 422);
        }
        if (! $identityRegistration->token_id || ! $identityRegistration->track_id || ! $identityRegistration->client_id) {
            return response()->json(['message' => 'Registration is missing token, track, or client.'], 422);
        }

        $token = $identityRegistration->token;
        if (! $token) {
            return response()->json(['message' => 'Token no longer exists.'], 422);
        }
        if ($token->status === 'in_use') {
            return response()->json(['message' => 'This token is already in use.'], 409);
        }

        $clientCategory = $identityRegistration->client_category ?? 'Regular';
        try {
            $result = $this->sessionService->bind(
                $token->qr_code_hash,
                $identityRegistration->track_id,
                $clientCategory,
                $user->id,
                ['client_id' => $identityRegistration->client_id, 'source' => 'public_verify'],
                'public_verify',
                null,
                $program->id
            );
        } catch (TokenInUseException $e) {
            return response()->json(['message' => 'This token is already in use.'], 409);
        } catch (ClientAlreadyQueuedException $e) {
            return response()->json(['message' => 'Client already has an active visit in the queue.'], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $session = $result['session'];
        $identityRegistration->update([
            'session_id' => $session->id,
            'status' => 'accepted',
            'resolved_at' => now(),
            'resolved_by_user_id' => $user->id,
        ]);

        $session->load('currentStation', 'serviceTrack');

        return response()->json([
            'message' => 'Visit started.',
            'session' => [
                'id' => $session->id,
                'alias' => $session->alias,
                'track' => [
                    'id' => $session->serviceTrack->id,
                    'name' => $session->serviceTrack->name,
                ],
                'current_station' => $session->currentStation ? [
                    'id' => $session->currentStation->id,
                    'name' => $session->currentStation->name,
                ] : null,
            ],
        ], 200);
    }

    /**
     * POST /api/identity-registrations/{id}/reject
     */
    public function reject(Request $request, IdentityRegistration $identityRegistration): JsonResponse
    {
        $user = $request->user();
        $program = $user->assignedStation?->program;

        if (! $program) {
            if (! $user->isAdmin() && ! $user->isSupervisorForAnyProgram()) {
                return response()->json(['message' => 'No station assigned.'], 422);
            }
            $program = Program::query()->where('id', (int) $identityRegistration->program_id)->where('is_active', true)->first();
            if (! $program) {
                return response()->json(['message' => 'Program not selected or inactive.'], 422);
            }
            if (! $user->isAdmin() && ! $user->isSupervisorForProgram($program->id)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }
        if ($identityRegistration->program_id !== $program->id || $identityRegistration->status !== 'pending') {
            return response()->json(['message' => 'Registration not found or already resolved.'], 404);
        }

        $identityRegistration->update([
            'status' => 'rejected',
            'resolved_at' => now(),
            'resolved_by_user_id' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Registration rejected.']);
    }

    /**
     * POST /api/identity-registrations/{id}/reveal-phone — staff reveal stored mobile for accept flow.
     */
    public function revealPhone(Request $request, IdentityRegistration $identityRegistration): JsonResponse
    {
        $user = $request->user();
        $program = $user->assignedStation?->program;

        if (! $program) {
            if (! $user->isAdmin() && ! $user->isSupervisorForAnyProgram()) {
                return response()->json(['message' => 'No station assigned.'], 422);
            }
            $program = Program::query()->where('id', (int) $identityRegistration->program_id)->where('is_active', true)->first();
            if (! $program) {
                return response()->json(['message' => 'Program not selected or inactive.'], 422);
            }
            if (! $user->isAdmin() && ! $user->isSupervisorForProgram($program->id)) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }
        if ($identityRegistration->program_id !== $program->id || $identityRegistration->status !== 'pending') {
            return response()->json(['message' => 'Registration not found or already resolved.'], 404);
        }
        if (! $identityRegistration->mobile_encrypted) {
            return response()->json(['message' => 'No mobile stored for this registration.'], 422);
        }

        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $mobile = $this->mobileCrypto->decrypt($identityRegistration->mobile_encrypted);
        $last2 = substr($mobile, -2);

        \App\Models\ClientIdAuditLog::create([
            'client_id' => $identityRegistration->client_id,
            'identity_registration_id' => $identityRegistration->id,
            'staff_user_id' => $user->id,
            'action' => 'phone_reveal',
            'mobile_last2' => $last2,
            'reason' => $request->input('reason'),
        ]);

        return response()->json(['mobile' => $mobile]);
    }
}
