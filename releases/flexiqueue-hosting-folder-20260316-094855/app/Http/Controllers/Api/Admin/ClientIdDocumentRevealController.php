<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RevealClientIdDocumentRequest;
use App\Models\ClientIdDocument;
use App\Services\ClientIdDocumentService;
use Illuminate\Http\JsonResponse;

class ClientIdDocumentRevealController extends Controller
{
    public function __construct(
        private ClientIdDocumentService $clientIdDocumentService,
    ) {
    }

    public function reveal(RevealClientIdDocumentRequest $request, ClientIdDocument $clientIdDocument): JsonResponse
    {
        $data = $request->validated();

        if (! ($data['confirm'] ?? false)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => [
                    'confirm' => ['Explicit confirmation is required to reveal this ID.'],
                ],
            ], 422);
        }

        $result = $this->clientIdDocumentService->revealForAdmin(
            $clientIdDocument,
            $request->user(),
            $data['reason'] ?? null,
        );

        return response()->json($result);
    }
}

