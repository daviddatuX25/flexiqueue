<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceAuthorizationRequest;
use App\Services\DeviceAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceAuthorizationRequestController extends Controller
{
    public function __construct(
        private DeviceAuthorizationService $deviceAuth
    ) {}

    public function approve(Request $request, DeviceAuthorizationRequest $device_authorization_request): JsonResponse
    {
        $user = $request->user();
        $program = $device_authorization_request->program;
        if (! $program || ! $program->is_active) {
            return response()->json(['message' => 'Program not found or inactive.'], 400);
        }

        $canApprove = ($user->isAdmin() && $user->site_id === $program->site_id)
            || $user->isSupervisorForProgram($program->id);
        if (! $canApprove) {
            return response()->json(['message' => 'You may only approve device authorization for your program or site.'], 403);
        }

        $token = $request->input('request_token');
        if (! $token || ! hash_equals($device_authorization_request->request_token ?? '', $token)) {
            return response()->json(['message' => 'Invalid or expired QR.'], 403);
        }

        if (! $device_authorization_request->isPending()) {
            return response()->json(['message' => 'Request already handled.'], 409);
        }

        $result = $this->deviceAuth->authorize(
            $program,
            $device_authorization_request->device_key,
            $device_authorization_request->scope
        );

        $device_authorization_request->update([
            'status' => DeviceAuthorizationRequest::STATUS_APPROVED,
            'responded_by_user_id' => $user->id,
            'responded_at' => now(),
            'approved_cookie_value' => $result['cookie_value'],
        ]);

        return response()->json(['message' => 'Device authorized.']);
    }
}
