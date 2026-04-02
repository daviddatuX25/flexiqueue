<?php

namespace App\Http\Controllers\Api\Edge;

use App\Http\Controllers\Controller;
use App\Services\EdgePairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PairController extends Controller
{
    public function __invoke(Request $request, EdgePairingService $pairingService): JsonResponse
    {
        $validated = $request->validate([
            'pairing_code' => ['required', 'string', 'size:8'],
        ]);

        try {
            $result = $pairingService->validateAndConsume($validated['pairing_code']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
