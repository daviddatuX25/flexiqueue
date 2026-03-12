<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IdentityRegistration;
use App\Models\Program;
use App\Services\ClientIdDocumentService;
use App\Services\ClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Staff triage: list pending identity registrations, accept (link to client + optional register ID), reject.
 * Direct: staff-only create of an already-accepted registration (no pending step).
 */
class IdentityRegistrationController extends Controller
{
    public function __construct(
        private ClientService $clientService,
        private ClientIdDocumentService $clientIdDocumentService,
    ) {}

    /**
     * GET /api/identity-registrations — list pending for active program.
     */
    public function index(Request $request): JsonResponse
    {
        $program = Program::where('is_active', true)->first();
        if (! $program) {
            return response()->json(['data' => []]);
        }

        $registrations = IdentityRegistration::query()
            ->forProgram($program->id)
            ->pending()
            ->with(['session.token', 'idVerifiedBy'])
            ->orderByDesc('requested_at')
            ->get();

        $data = $registrations->map(fn (IdentityRegistration $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'birth_year' => $r->birth_year,
            'client_category' => $r->client_category,
            'id_type' => $r->id_type,
            'id_number_last4' => $r->id_number_last4,
            'id_verified_at' => $r->id_verified_at?->toIso8601String(),
            'id_verified_by_user_id' => $r->id_verified_by_user_id,
            'id_verified_by' => $r->idVerifiedBy?->name,
            'requested_at' => $r->requested_at?->toIso8601String(),
            'session_id' => $r->session_id,
            'session_alias' => $r->session?->token?->physical_id ?? null,
        ])->values()->all();

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/identity-registrations/direct — staff creates an identity registration that is already accepted.
     * No pending step; no queue session. Requires name, birth_year, client_category.
     */
    public function direct(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'birth_year' => ['required', 'integer', 'min:1900', 'max:2100'],
            'client_category' => ['required', 'string', 'max:50'],
            'id_type' => ['nullable', 'string', 'max:50'],
            'id_number' => ['nullable', 'string', 'max:255'],
        ]);

        $program = Program::where('is_active', true)->first();
        if (! $program) {
            return response()->json(['message' => 'No active program.'], 422);
        }

        $name = $request->string('name')->trim();
        $birthYear = (int) $request->input('birth_year');
        $clientCategory = $request->string('client_category')->trim();
        $idType = $request->input('id_type');
        $idNumberRaw = $request->input('id_number');
        $idNumberTrimmed = is_string($idNumberRaw) ? trim($idNumberRaw) : '';

        $idNumberEncrypted = null;
        $idNumberLast4 = null;
        if ($idNumberTrimmed !== '' && $idType !== null && $idType !== '') {
            $idNumberEncrypted = Crypt::encryptString($idNumberTrimmed);
            $idNumberLast4 = $this->clientIdDocumentService->getLast4FromRawNumber($idNumberTrimmed);
        }

        try {
            DB::transaction(function () use ($program, $request, $name, $birthYear, $clientCategory, $idType, $idNumberEncrypted, $idNumberLast4, $idNumberTrimmed) {
                $client = $this->clientService->createClient($name, $birthYear);

                IdentityRegistration::create([
                    'program_id' => $program->id,
                    'session_id' => null,
                    'name' => $name,
                    'birth_year' => $birthYear,
                    'client_category' => $clientCategory,
                    'client_id' => $client->id,
                    'id_type' => $idType !== '' && $idType !== null ? $idType : null,
                    'id_number_encrypted' => $idNumberEncrypted,
                    'id_number_last4' => $idNumberLast4,
                    'id_verified_at' => ($idNumberTrimmed !== '' && $idType) ? now() : null,
                    'id_verified_by_user_id' => ($idNumberTrimmed !== '' && $idType) ? $request->user()->id : null,
                    'status' => 'accepted',
                    'requested_at' => now(),
                    'resolved_at' => now(),
                    'resolved_by_user_id' => $request->user()->id,
                ]);

                if ($idNumberTrimmed !== '' && $idType !== null && $idType !== '') {
                    $this->clientIdDocumentService->createForClient($client, $idType, $idNumberTrimmed);
                }
            });
        } catch (\App\Exceptions\DuplicateClientIdDocumentException $e) {
            return response()->json(['message' => 'This ID number is already registered to another client.'], 409);
        }

        return response()->json(['message' => 'Registration created.']);
    }

    /**
     * GET /api/identity-registrations/{id}/possible-matches — clients matching name + birth_year for accept flow.
     */
    public function possibleMatches(IdentityRegistration $identityRegistration): JsonResponse
    {
        $program = Program::where('is_active', true)->first();
        if (! $program || $identityRegistration->program_id !== $program->id || $identityRegistration->status !== 'pending') {
            return response()->json(['data' => []]);
        }

        $name = $identityRegistration->name ?? '';
        $birthYear = $identityRegistration->birth_year;

        if ($name === '') {
            return response()->json(['data' => []]);
        }

        $params = [
            'name' => $name,
            'birth_year' => $birthYear,
            'per_page' => 10,
            'page' => 1,
        ];
        $paginator = $this->clientService->searchClients($params);

        $data = $paginator->map(fn ($client) => [
            'id' => $client->id,
            'name' => $client->name,
            'birth_year' => $client->birth_year,
            'has_id_document' => $client->id_documents_count > 0,
            'id_documents_count' => (int) ($client->id_documents_count ?? 0),
        ])->values()->all();

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/identity-registrations/{id}/verify-id — staff scan physical ID; compare to stored; set id_verified_at + id_verified_by_user_id.
     */
    public function verifyId(Request $request, IdentityRegistration $identityRegistration): JsonResponse
    {
        $request->validate([
            'id_type' => ['nullable', 'string', 'max:50'],
            'id_number' => ['required', 'string', 'max:255'],
        ]);

        $program = Program::where('is_active', true)->first();
        if (! $program || $identityRegistration->program_id !== $program->id || $identityRegistration->status !== 'pending') {
            return response()->json(['message' => 'Registration not found or already resolved.'], 404);
        }

        if (empty($identityRegistration->id_number_encrypted)) {
            return response()->json(['message' => 'No ID to verify.'], 422);
        }

        $idType = $request->input('id_type');
        if ($idType !== null && $idType !== '' && $identityRegistration->id_type !== null && $idType !== $identityRegistration->id_type) {
            return response()->json(['message' => 'ID type does not match.'], 422);
        }

        if (! $this->clientIdDocumentService->scannedNumberMatchesStored($request->string('id_number')->trim(), $identityRegistration->id_number_encrypted)) {
            return response()->json(['message' => 'ID does not match.'], 422);
        }

        $storedType = $identityRegistration->id_type;
        if (is_string($storedType) && $storedType !== '') {
            $existing = $this->clientIdDocumentService->lookupById($storedType, $request->string('id_number')->trim());
            if (! empty($existing['id_document'])) {
                return response()->json(['message' => 'This ID number is already registered to another client.'], 409);
            }
        }

        $identityRegistration->update([
            'id_verified_at' => now(),
            'id_verified_by_user_id' => $request->user()->id,
        ]);
        $identityRegistration->load('idVerifiedBy');

        return response()->json([
            'verified' => true,
            'id_verified_at' => $identityRegistration->id_verified_at->toIso8601String(),
            'id_verified_by_user_id' => $identityRegistration->id_verified_by_user_id,
            'id_verified_by' => $identityRegistration->idVerifiedBy?->name,
        ]);
    }

    /**
     * POST /api/identity-registrations/{id}/accept — verify name, birth_year, client_category; link to existing or create client; optional register_id.
     */
    public function accept(Request $request, IdentityRegistration $identityRegistration): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'birth_year' => ['required', 'integer', 'min:1900', 'max:2100'],
            'client_category' => ['required', 'string', 'max:50'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'create_new_client' => ['nullable', 'boolean'],
            'register_id' => ['nullable', 'array'],
            'register_id.id_type' => ['required_with:register_id', 'string', 'max:50'],
            'register_id.id_number' => ['required_with:register_id', 'string', 'max:255'],
        ]);

        $program = Program::where('is_active', true)->first();
        if (! $program || $identityRegistration->program_id !== $program->id || $identityRegistration->status !== 'pending') {
            return response()->json(['message' => 'Registration not found or already resolved.'], 404);
        }

        $createNew = $request->boolean('create_new_client');
        $clientId = $request->integer('client_id');
        if (! $createNew && ! $clientId) {
            return response()->json(['message' => 'Provide client_id or create_new_client.'], 422);
        }

        try {
            DB::transaction(function () use ($identityRegistration, $request, $program, $createNew, $clientId) {
                $name = $request->string('name')->trim();
                $birthYear = (int) $request->input('birth_year');
                $clientCategory = $request->string('client_category')->trim();

                if ($createNew) {
                    $client = $this->clientService->createClient($name, $birthYear);
                    $clientId = $client->id;
                } else {
                    $client = \App\Models\Client::findOrFail($clientId);
                }

                $identityRegistration->update([
                    'status' => 'accepted',
                    'client_id' => $clientId,
                    'name' => $name,
                    'birth_year' => $birthYear,
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

                // When registration already has stored ID, do not register a new one (frontend shows Verify ID instead).
                $registerId = $identityRegistration->id_number_encrypted ? null : $request->input('register_id');
                if (is_array($registerId) && ! empty($registerId['id_type'] ?? '') && ! empty($registerId['id_number'] ?? '')) {
                    $this->clientIdDocumentService->createForClient($client, $registerId['id_type'], $registerId['id_number']);
                }

                // When registration has stored ID and has been verified, attach it to selected existing client on accept.
                if (! $createNew && $identityRegistration->id_number_encrypted && $identityRegistration->id_verified_at && $identityRegistration->id_type) {
                    $rawIdNumber = Crypt::decryptString($identityRegistration->id_number_encrypted);
                    $this->clientIdDocumentService->createForClient($client, $identityRegistration->id_type, $rawIdNumber);
                }
            });
        } catch (\App\Exceptions\DuplicateClientIdDocumentException $e) {
            return response()->json(['message' => 'This ID number is already registered to another client.'], 409);
        }

        return response()->json(['message' => 'Registration accepted.']);
    }

    /**
     * POST /api/identity-registrations/{id}/reject
     */
    public function reject(Request $request, IdentityRegistration $identityRegistration): JsonResponse
    {
        $program = Program::where('is_active', true)->first();
        if (! $program || $identityRegistration->program_id !== $program->id || $identityRegistration->status !== 'pending') {
            return response()->json(['message' => 'Registration not found or already resolved.'], 404);
        }

        $identityRegistration->update([
            'status' => 'rejected',
            'resolved_at' => now(),
            'resolved_by_user_id' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Registration rejected.']);
    }
}
