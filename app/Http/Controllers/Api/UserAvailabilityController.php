<?php

namespace App\Http\Controllers\Api;

use App\Events\StaffAvailabilityUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUserAvailabilityRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per staff-availability-status plan: PATCH /api/users/me/availability.
 * Updates the authenticated user's availability status (available, on_break, away).
 * Per ISSUES-ELABORATION §23: logs availability_change to staff_activity_log.
 * Per flexiqueue-wrx: broadcasts to display.activity so the display board updates in real time.
 */
class UserAvailabilityController extends Controller
{
    public function update(UpdateUserAvailabilityRequest $request): JsonResponse
    {
        $user = $request->user();
        $status = $request->validated('status');
        $oldStatus = $user->availability_status ?? 'offline';

        $user->update([
            'availability_status' => $status,
            'availability_updated_at' => now(),
        ]);

        $this->logAvailabilityChange($user->id, $oldStatus, $status);

        broadcast(new StaffAvailabilityUpdated(
            $user->id,
            $user->availability_status ?? 'offline',
            $user->name ?? ''
        ));

        return response()->json([
            'availability_status' => $user->availability_status,
            'availability_updated_at' => $user->availability_updated_at?->toIso8601String(),
        ]);
    }

    private function logAvailabilityChange(int $userId, string $oldValue, string $newValue): void
    {
        if (! Schema::hasTable('staff_activity_log')) {
            return;
        }
        DB::table('staff_activity_log')->insert([
            'user_id' => $userId,
            'action_type' => 'availability_change',
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'metadata' => null,
            'created_at' => now(),
        ]);
    }
}
