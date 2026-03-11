<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\GenerateTemporaryQrRequest;
use App\Services\TemporaryQrService;
use Illuminate\Http\JsonResponse;

/**
 * Per docs/plans/PIN-QR-AUTHORIZATION-SYSTEM.md AUTH-4: Temporary QR generation.
 */
class TemporaryQrController
{
    public function __construct(
        private TemporaryQrService $temporaryQrService
    ) {}

    /**
     * Generate a one-time QR. Supervisor/admin only. Staff scans to authorize.
     */
    public function __invoke(GenerateTemporaryQrRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isSupervisorForAnyProgram()) {
            return response()->json(['message' => 'Only supervisors or admins can generate temporary QR codes.'], 403);
        }

        $result = $this->temporaryQrService->generate(
            $user,
            $request->validated('program_id'),
            (string) $request->validated('expiry_mode'),
            $request->validated('expires_in_seconds'),
            $request->validated('max_uses')
        );

        return response()->json($result, 201);
    }
}
