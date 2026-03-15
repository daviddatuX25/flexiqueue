<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceAuthorizationRequest;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class PublicDeviceAuthorizationRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id' => ['required', 'integer', 'exists:programs,id'],
            'device_key' => ['nullable', 'string', 'max:255'],
            'scope' => ['nullable', 'string', 'in:session,persistent'],
        ]);

        $program = Program::findOrFail($validated['program_id']);
        if (! $program->is_active) {
            return response()->json(['message' => 'Program not active.'], 400);
        }

        $deviceKey = isset($validated['device_key']) && trim($validated['device_key']) !== ''
            ? trim($validated['device_key'])
            : Str::uuid()->toString();
        $scope = $validated['scope'] ?? 'session';

        $req = DeviceAuthorizationRequest::create([
            'program_id' => $program->id,
            'device_key' => $deviceKey,
            'device_key_hash' => hash('sha256', $deviceKey),
            'request_token' => Str::random(64),
            'status' => DeviceAuthorizationRequest::STATUS_PENDING,
            'scope' => $scope,
        ]);

        return response()->json([
            'id' => $req->id,
            'request_token' => $req->request_token,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $token = $request->query('token');
        if (! $token) {
            return response()->json(['message' => 'Token required.'], 422);
        }

        $req = DeviceAuthorizationRequest::find($id);
        if (! $req || ! hash_equals($req->request_token ?? '', $token)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($req->status !== DeviceAuthorizationRequest::STATUS_APPROVED) {
            return response()->json(['status' => $req->status]);
        }

        $cookieValue = $req->approved_cookie_value;
        if (! $cookieValue) {
            return response()->json(['status' => $req->status]);
        }

        $cookieName = \App\Services\DeviceAuthorizationService::COOKIE_NAME_PREFIX . $req->program_id;
        $cookie = Cookie::create($cookieName)
            ->withValue($cookieValue)
            ->withExpires(now()->addDays(365))
            ->withPath('/')
            ->withSecure($request->secure())
            ->withHttpOnly(true)
            ->withSameSite('lax');

        return response()->json(['status' => 'approved'])
            ->cookie($cookie);
    }

    /**
     * Cancel a pending request (e.g. when user navigates away). No auth; token required.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'request_token' => ['required', 'string', 'size:64'],
        ]);

        $req = DeviceAuthorizationRequest::find($id);
        if (! $req || ! hash_equals($req->request_token ?? '', $validated['request_token'])) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (! $req->isPending()) {
            return response()->json(['message' => 'Request already handled.'], 409);
        }

        $req->update(['status' => DeviceAuthorizationRequest::STATUS_CANCELLED]);

        return response()->json(['message' => 'Request cancelled.'], 200);
    }
}
