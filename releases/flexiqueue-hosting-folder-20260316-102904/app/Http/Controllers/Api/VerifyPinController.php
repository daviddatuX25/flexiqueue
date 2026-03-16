<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyPinRequest;
use App\Services\PinService;
use Illuminate\Http\JsonResponse;

/**
 * Per 08-API-SPEC-PHASE1 §1.3: One-time supervisor PIN verification for override actions.
 */
class VerifyPinController extends Controller
{
    public function __construct(
        private PinService $pinService
    ) {}

    /**
     * Verify supervisor PIN. Rate limit: 5 attempts / minute per user_id (middleware).
     */
    public function __invoke(VerifyPinRequest $request): JsonResponse
    {
        $result = $this->pinService->validate(
            (int) $request->validated('user_id'),
            $request->validated('pin')
        );

        if (! $result) {
            return response()->json([
                'verified' => false,
                'message' => 'Invalid PIN.',
            ], 401);
        }

        return response()->json($result);
    }
}
