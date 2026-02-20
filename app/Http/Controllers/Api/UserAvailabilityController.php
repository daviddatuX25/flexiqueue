<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUserAvailabilityRequest;
use Illuminate\Http\JsonResponse;

/**
 * Per staff-availability-status plan: PATCH /api/users/me/availability.
 * Updates the authenticated user's availability status (available, on_break, away).
 */
class UserAvailabilityController extends Controller
{
    public function update(UpdateUserAvailabilityRequest $request): JsonResponse
    {
        $user = $request->user();
        $status = $request->validated('status');

        $user->update([
            'availability_status' => $status,
            'availability_updated_at' => now(),
        ]);

        return response()->json([
            'availability_status' => $user->availability_status,
            'availability_updated_at' => $user->availability_updated_at?->toIso8601String(),
        ]);
    }
}
