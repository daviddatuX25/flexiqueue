<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceUnlockRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authenticated: approve device unlock requests (supervisor/admin scans QR on Track overrides or footer).
 */
class DeviceUnlockRequestController extends Controller
{
    public function approve(Request $request, DeviceUnlockRequest $device_unlock_request): JsonResponse
    {
        $user = $request->user();
        $program = $device_unlock_request->program;
        if (! $program || ! $program->is_active) {
            return response()->json(['message' => 'Program not found or inactive.'], 400);
        }

        $canApprove = ($user->isAdmin() && $user->site_id === $program->site_id)
            || $user->isSupervisorForProgram($program->id);
        if (! $canApprove) {
            return response()->json(['message' => 'You may only approve device unlock for your program or site.'], 403);
        }

        $token = $request->input('request_token');
        if (! $token || ! hash_equals($device_unlock_request->request_token ?? '', $token)) {
            return response()->json(['message' => 'Invalid or expired QR.'], 403);
        }

        if (! $device_unlock_request->isPending()) {
            return response()->json(['message' => 'Request already handled.'], 409);
        }

        $device_unlock_request->update([
            'status' => DeviceUnlockRequest::STATUS_APPROVED,
            'responded_by_user_id' => $user->id,
            'responded_at' => now(),
        ]);

        return response()->json(['message' => 'Device unlock approved.']);
    }
}
