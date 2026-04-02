<?php

namespace App\Http\Controllers\Api;

use App\Events\StaffAvailabilityUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUserAvailabilityRequest;
use App\Services\StaffActivityLogService;
use Illuminate\Http\JsonResponse;

/**
 * Per staff-availability-status plan: PATCH /api/users/me/availability.
 * Updates the authenticated user's availability status (available, on_break, away).
 * Per ISSUES-ELABORATION §23: logs availability_change to staff_activity_log.
 * Per flexiqueue-wrx: broadcasts to display.activity so the display board updates in real time.
 */
class UserAvailabilityController extends Controller
{
    public function __construct(private readonly StaffActivityLogService $activityLog) {}

    public function update(UpdateUserAvailabilityRequest $request): JsonResponse
    {
        $user = $request->user();
        $status = $request->validated('status');
        $oldStatus = $user->availability_status ?? 'offline';

        $user->update([
            'availability_status' => $status,
            'availability_updated_at' => now(),
        ]);

        $this->activityLog->logActivity($user->id, 'availability_change', $oldStatus, $status);

        $programId = $user->assignedStation?->program_id;
        if ($programId !== null) {
            broadcast(new StaffAvailabilityUpdated(
                $programId,
                $user->id,
                $user->availability_status ?? 'offline',
                $user->name ?? ''
            ));
        }

        return response()->json([
            'availability_status' => $user->availability_status,
            'availability_updated_at' => $user->availability_updated_at?->toIso8601String(),
        ]);
    }


}
