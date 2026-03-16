<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DuplicateClientIdDocumentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AttachClientIdDocumentRequest;
use App\Http\Requests\ClientLookupByIdRequest;
use App\Http\Requests\ClientSearchRequest;
use App\Http\Requests\StoreClientRequest;
use App\Models\Client;
use App\Services\ClientIdDocumentService;
use App\Services\ClientService;
use Illuminate\Http\JsonResponse;

class ClientController extends Controller
{
    public function __construct(
        private ClientService $clientService,
        private ClientIdDocumentService $clientIdDocumentService,
    ) {
    }

    public function search(ClientSearchRequest $request): JsonResponse
    {
        $params = $request->validatedSearchParams();
        $paginator = $this->clientService->searchClients($params);

        $data = $paginator->map(function ($client) {
            return [
                'id' => $client->id,
                'name' => $client->name,
                'birth_year' => $client->birth_year,
                'has_id_document' => $client->id_documents_count > 0,
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

    public function lookupById(ClientLookupByIdRequest $request): JsonResponse
    {
        $data = $request->validated();
        $idType = isset($data['id_type']) && $data['id_type'] !== '' && $data['id_type'] !== null
            ? $data['id_type']
            : null;
        $idNumber = $data['id_number'];

        if ($idType !== null) {
            $result = $this->clientIdDocumentService->lookupById($idType, $idNumber);
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

        $result = $this->clientIdDocumentService->lookupByIdNumberOnly($idNumber);
        if ($result['match_status'] === 'ambiguous') {
            return response()->json([
                'match_status' => 'ambiguous',
                'message' => 'Can\'t auto-detect. Please select ID type first.',
                'id_types' => $result['id_types'],
            ]);
        }
        if ($result['match_status'] === 'not_found') {
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

    public function store(StoreClientRequest $request): JsonResponse
    {
        $data = $request->validated();

        $client = $this->clientService->createClient(
            $data['name'],
            (int) $data['birth_year'],
        );

        $idDocumentPayload = $data['id_document'] ?? null;
        $idDocument = null;

        if (is_array($idDocumentPayload)) {
            try {
                $idDocument = $this->clientIdDocumentService->createForClient(
                    $client,
                    $idDocumentPayload['id_type'],
                    $idDocumentPayload['id_number'],
                );
            } catch (DuplicateClientIdDocumentException $e) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error_code' => 'id_document_duplicate',
                    'hint' => 'Use the lookup-by-id endpoint to attach this ID to the existing client instead of creating a new client.',
                ], 409);
            }
        }

        $response = [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'birth_year' => $client->birth_year,
            ],
        ];

        if ($idDocument) {
            $idLast4 = $this->clientIdDocumentService->getIdLast4FromDocument($idDocument);

            $response['id_document'] = [
                'id' => $idDocument->id,
                'id_type' => $idDocument->id_type,
                'id_last4' => $idLast4,
            ];
        } else {
            $response['id_document'] = null;
        }

        return response()->json($response, 201);
    }

    public function attachIdDocument(AttachClientIdDocumentRequest $request, Client $client): JsonResponse
    {
        $data = $request->validated();

        try {
            $doc = $this->clientIdDocumentService->createForClient(
                $client,
                $data['id_type'],
                $data['id_number'],
            );
        } catch (DuplicateClientIdDocumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'id_document_duplicate',
            ], 409);
        }

        $idLast4 = $this->clientIdDocumentService->getIdLast4FromDocument($doc);

        return response()->json([
            'id_document' => [
                'id' => $doc->id,
                'id_type' => $doc->id_type,
                'id_last4' => $idLast4,
            ],
        ], 201);
    }
}

