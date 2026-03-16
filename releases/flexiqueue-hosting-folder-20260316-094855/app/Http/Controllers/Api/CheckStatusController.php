<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CheckStatusService;
use Illuminate\Http\JsonResponse;

/**
 * Per 08-API-SPEC-PHASE1 §2.1: Public token status (no auth).
 */
class CheckStatusController extends Controller
{
    public function __construct(
        private CheckStatusService $checkStatusService
    ) {}

    /**
     * Get token status by qr_hash. Public.
     */
    public function show(string $qr_hash): JsonResponse
    {
        $data = $this->checkStatusService->getStatus($qr_hash);

        if ($data['result'] === 'not_found') {
            return response()->json(['message' => 'Token not found.'], 404);
        }

        if ($data['result'] === 'unavailable') {
            return response()->json([
                'alias' => $data['alias'],
                'status' => 'unavailable',
                'message' => $data['message'] ?? 'Token marked as '.($data['status'] ?? 'unavailable').'.',
            ], 200);
        }

        if ($data['result'] === 'available') {
            return response()->json([
                'alias' => $data['alias'],
                'status' => 'available',
                'message' => $data['message'],
            ], 200);
        }

        // in_use: full session payload per spec §2.1
        return response()->json([
            'alias' => $data['alias'],
            'track' => $data['track'],
            'client_category' => $data['client_category'],
            'status' => $data['status'],
            'current_station' => $data['current_station'],
            'progress' => $data['progress'],
            'estimated_wait_minutes' => $data['estimated_wait_minutes'],
            'started_at' => $data['started_at'],
        ], 200);
    }
}
