<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GenerateTemporaryPinRequest;
use App\Services\TemporaryPinService;
use Illuminate\Http\JsonResponse;

/**
 * Per docs/plans/PIN-QR-AUTHORIZATION-SYSTEM.md AUTH-3: Temporary PIN generation.
 */
class TemporaryPinController
{
    public function __construct(
        private TemporaryPinService $temporaryPinService
    ) {}

    /**
     * Generate a one-time 6-digit PIN. Supervisor/admin only.
     */
    public function __invoke(GenerateTemporaryPinRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isSupervisorForAnyProgram()) {
            return response()->json(['message' => 'Only supervisors or admins can generate temporary PINs.'], 403);
        }

        $result = $this->temporaryPinService->generate(
            $user,
            $request->validated('program_id'),
            $request->validated('expires_in_seconds')
        );

        return response()->json($result, 201);
    }
}
