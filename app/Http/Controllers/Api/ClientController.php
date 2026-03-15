<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\StationPageController;
use App\Http\Requests\ClientSearchRequest;
use App\Http\Requests\StoreClientRequest;
use App\Models\Client;
use App\Models\ClientIdAuditLog;
use App\Models\Program;
use App\Services\ClientService;
use App\Services\MobileCryptoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    public function __construct(
        private ClientService $clientService,
        private MobileCryptoService $mobileCrypto,
    ) {
    }

    /**
     * Resolve site_id for client operations. Per site-scoping-migration-spec §3:
     * triage context uses program.site_id; otherwise user.site_id.
     */
    private function resolveSiteId(Request $request): ?int
    {
        $user = $request->user();
        $programId = $request->query('program_id') ?? $request->input('program_id');
        if ($programId !== null) {
            $program = Program::query()->find((int) $programId);
            if ($program) {
                return $program->site_id;
            }
        }
        if ($user->assignedStation?->program) {
            return $user->assignedStation->program->site_id;
        }
        $sessionProgramId = $request->session()->get(StationPageController::SESSION_KEY_PROGRAM_ID);
        if ($sessionProgramId) {
            $program = Program::query()->find((int) $sessionProgramId);
            if ($program) {
                return $program->site_id;
            }
        }

        return $user->site_id;
    }

    public function search(ClientSearchRequest $request): JsonResponse
    {
        $siteId = $this->resolveSiteId($request);
        if ($siteId === null) {
            return response()->json(['message' => 'No program or site context for client search.'], 422);
        }
        $params = $request->validatedSearchParams();
        $params['site_id'] = $siteId;
        $paginator = $this->clientService->searchClients($params);

        $data = $paginator->map(function ($client) {
            $mobileMasked = $client->mobile_encrypted
                ? $this->mobileCrypto->mask($this->mobileCrypto->decrypt($client->mobile_encrypted))
                : null;

            return [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'middle_name' => $client->middle_name,
                'last_name' => $client->last_name,
                'birth_date' => $client->birth_date?->format('Y-m-d'),
                'address_line_1' => $client->address_line_1,
                'address_line_2' => $client->address_line_2,
                'city' => $client->city,
                'state' => $client->state,
                'postal_code' => $client->postal_code,
                'country' => $client->country,
                'mobile_masked' => $mobileMasked,
            ];
        })->values()->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    public function searchByPhone(Request $request): JsonResponse
    {
        $request->validate([
            'mobile' => ['required', 'string', 'max:30'],
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
        ]);

        $siteId = $this->resolveSiteId($request);
        if ($siteId === null) {
            return response()->json(['message' => 'No program or site context for client search.'], 422);
        }

        $client = $this->clientService->searchClientsByPhone($request->input('mobile'), $siteId);
        if (! $client) {
            return response()->json([
                'match_status' => 'not_found',
                'client' => null,
            ]);
        }
        if ($client->site_id !== $siteId) {
            return response()->json([
                'match_status' => 'not_found',
                'client' => null,
            ]);
        }

        $mobileMasked = $client->mobile_encrypted
            ? $this->mobileCrypto->mask($this->mobileCrypto->decrypt($client->mobile_encrypted))
            : null;

        return response()->json([
            'match_status' => 'existing',
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'middle_name' => $client->middle_name,
                'last_name' => $client->last_name,
                'birth_date' => $client->birth_date?->format('Y-m-d'),
                'address_line_1' => $client->address_line_1,
                'address_line_2' => $client->address_line_2,
                'city' => $client->city,
                'state' => $client->state,
                'postal_code' => $client->postal_code,
                'country' => $client->country,
                'mobile_masked' => $mobileMasked,
            ],
        ]);
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $siteId = $this->resolveSiteId($request);
        if ($siteId === null) {
            return response()->json(['message' => 'No program or site context for client creation.'], 403);
        }
        $data = $request->validated();

        $address = null;
        if (! empty(array_filter([
            $data['address_line_1'] ?? null,
            $data['address_line_2'] ?? null,
            $data['city'] ?? null,
            $data['state'] ?? null,
            $data['postal_code'] ?? null,
            $data['country'] ?? null,
        ]))) {
            $address = [
                'address_line_1' => $data['address_line_1'] ?? null,
                'address_line_2' => $data['address_line_2'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'country' => $data['country'] ?? null,
            ];
        }

        $client = $this->clientService->createClient(
            $data['first_name'],
            $data['last_name'],
            $data['birth_date'],
            $siteId,
            $data['mobile'] ?? null,
            $data['middle_name'] ?? null,
            $address,
        );

        $mobileMasked = $client->mobile_encrypted
            ? $this->mobileCrypto->mask($this->mobileCrypto->decrypt($client->mobile_encrypted))
            : null;

        return response()->json([
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'middle_name' => $client->middle_name,
                'last_name' => $client->last_name,
                'birth_date' => $client->birth_date?->format('Y-m-d'),
                'address_line_1' => $client->address_line_1,
                'address_line_2' => $client->address_line_2,
                'city' => $client->city,
                'state' => $client->state,
                'postal_code' => $client->postal_code,
                'country' => $client->country,
                'mobile_masked' => $mobileMasked,
            ],
        ], 201);
    }

    public function revealPhone(Request $request, Client $client): JsonResponse
    {
        $siteId = $this->resolveSiteId($request);
        if ($siteId === null || $client->site_id !== $siteId) {
            abort(404);
        }
        if (! $client->mobile_encrypted) {
            return response()->json(['message' => 'Client has no stored mobile number.'], 422);
        }

        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'confirm' => ['required', 'accepted'],
        ]);

        $mobile = $this->mobileCrypto->decrypt($client->mobile_encrypted);
        $last2 = substr($mobile, -2);

        ClientIdAuditLog::create([
            'client_id' => $client->id,
            'staff_user_id' => $request->user()->id,
            'action' => 'phone_reveal',
            'mobile_last2' => $last2,
            'reason' => $request->input('reason'),
        ]);

        return response()->json(['mobile' => $mobile]);
    }

    public function updateMobile(Request $request, Client $client): JsonResponse
    {
        $siteId = $this->resolveSiteId($request);
        if ($siteId === null || $client->site_id !== $siteId) {
            abort(404);
        }

        $data = $request->validate([
            'mobile' => ['required', 'string', 'max:30'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $normalized = \App\Support\MobileNormalizer::normalize($data['mobile']);
        if ($normalized === '') {
            throw ValidationException::withMessages(['mobile' => ['Invalid mobile number.']]);
        }

        $hash = $this->mobileCrypto->hash($normalized);
        $existing = Client::where('mobile_hash', $hash)->where('id', '!=', $client->id)->first();
        if ($existing) {
            return response()->json(['message' => 'Another client already has this mobile number.'], 409);
        }

        $last2 = substr($normalized, -2);

        ClientIdAuditLog::create([
            'client_id' => $client->id,
            'staff_user_id' => $request->user()->id,
            'action' => 'phone_update',
            'mobile_last2' => $last2,
            'reason' => $data['reason'],
        ]);

        $client->update([
            'mobile_encrypted' => $this->mobileCrypto->encrypt($normalized),
            'mobile_hash' => $hash,
        ]);

        return response()->json([
            'mobile_masked' => $this->mobileCrypto->mask($normalized),
        ]);
    }
}
