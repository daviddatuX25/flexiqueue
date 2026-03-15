<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReassignClientIdDocumentRequest;
use App\Models\Client;
use App\Models\ClientIdDocument;
use App\Services\ClientIdDocumentService;
use Illuminate\Http\JsonResponse;

class ClientIdDocumentAdminController extends Controller
{
    public function __construct(
        private ClientIdDocumentService $clientIdDocumentService,
    ) {}

    public function destroy(ClientIdDocument $client_id_document): JsonResponse
    {
        try {
            $this->clientIdDocumentService->deleteDocument($client_id_document);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['deleted' => true]);
    }

    public function reassign(ReassignClientIdDocumentRequest $request, ClientIdDocument $client_id_document): JsonResponse
    {
        $targetClientId = (int) $request->validated('target_client_id');
        /** @var Client $target */
        $target = Client::findOrFail($targetClientId);

        $doc = $this->clientIdDocumentService->reassignDocument($client_id_document, $target);

        return response()->json([
            'client_id_document' => [
                'id' => $doc->id,
                'client_id' => $doc->client_id,
            ],
        ]);
    }
}

