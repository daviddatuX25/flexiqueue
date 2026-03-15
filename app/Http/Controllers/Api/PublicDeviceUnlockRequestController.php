<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceUnlockWithAuthRequest;
use App\Models\DeviceUnlockRequest;
use App\Models\Program;
use App\Services\PinService;
use App\Support\DeviceLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public: create device unlock request (device shows QR), poll status, and cancel on leave.
 * Also: unlock with PIN/QR (same as device authorization) for uniform enter/exit flow.
 */
class PublicDeviceUnlockRequestController extends Controller
{
    public function __construct(
        private PinService $pinService
    ) {}

    /**
     * POST /api/public/device-unlock-with-auth
     * Unlock using the same PIN or QR as when entering. Requires current device lock; clears lock and returns redirect_url.
     */
    public function unlockWithAuth(DeviceUnlockWithAuthRequest $request): JsonResponse
    {
        $lock = DeviceLock::decode($request);
        if ($lock === null) {
            return response()->json(['message' => 'No device lock to unlock.'], 400);
        }

        $program = Program::query()
            ->whereHas('site', fn ($q) => $q->where('slug', $lock['site_slug']))
            ->where('slug', $lock['program_slug'])
            ->where('is_active', true)
            ->first();

        if (! $program || ! $program->site) {
            return response()->json(['message' => 'Program not found or inactive.'], 400);
        }

        $pin = $request->input('pin');
        $qr = $request->input('qr_scan_token');
        $hasPin = is_string($pin) && trim($pin) !== '';
        $hasQr = is_string($qr) && trim($qr) !== '';

        $verified = false;
        if ($hasPin) {
            $verified = $this->pinService->validatePinForProgram($program->id, trim($pin)) !== null;
        } elseif ($hasQr) {
            $verified = $this->pinService->validateQrForProgram($program->id, trim($qr)) !== null;
        }

        if (! $verified) {
            return response()->json(['message' => 'Invalid PIN or QR code.'], 401);
        }

        DeviceLock::clearFromSession($request);
        $redirectUrl = '/site/'.$program->site->slug.'/program/'.$program->slug.'/devices';

        return response()
            ->json(['redirect_url' => $redirectUrl], 200)
            ->cookie(DeviceLock::clearCookie());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id' => ['required', 'integer', 'exists:programs,id'],
        ]);

        $program = Program::findOrFail($validated['program_id']);
        if (! $program->is_active) {
            return response()->json(['message' => 'Program not active.'], 400);
        }

        $req = DeviceUnlockRequest::create([
            'program_id' => $program->id,
            'request_token' => Str::random(64),
            'status' => DeviceUnlockRequest::STATUS_PENDING,
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

        $req = DeviceUnlockRequest::find($id);
        if (! $req || ! hash_equals($req->request_token ?? '', $token)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(['status' => $req->status]);
    }

    /**
     * Cancel a pending request (e.g. when user navigates away or closes page). No auth; token required.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'request_token' => ['required', 'string', 'size:64'],
        ]);

        $req = DeviceUnlockRequest::find($id);
        if (! $req || ! hash_equals($req->request_token ?? '', $validated['request_token'])) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (! $req->isPending()) {
            return response()->json(['message' => 'Request already handled.'], 409);
        }

        $req->update(['status' => DeviceUnlockRequest::STATUS_CANCELLED]);

        return response()->json(['message' => 'Request cancelled.'], 200);
    }

    /**
     * Consume an approved unlock request: clear device lock cookie and return redirect URL to choose-device page.
     * Only succeeds when the request is approved and token matches; otherwise 403. Per plan Option B.
     */
    public function consume(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'request_token' => ['required', 'string', 'size:64'],
        ]);

        $req = DeviceUnlockRequest::find($id);
        if (! $req || ! hash_equals($req->request_token ?? '', $validated['request_token'])) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($req->status !== DeviceUnlockRequest::STATUS_APPROVED) {
            return response()->json(['message' => 'Request not approved.'], 403);
        }

        $program = $req->program;
        if (! $program || ! $program->is_active) {
            return response()->json(['message' => 'Program not found or inactive.'], 400);
        }

        $site = $program->site;
        if (! $site) {
            return response()->json(['message' => 'Site not found.'], 400);
        }

        $redirectUrl = '/site/'.$site->slug.'/program/'.$program->slug.'/devices';

        DeviceLock::clearFromSession($request);

        return response()
            ->json(['redirect_url' => $redirectUrl], 200)
            ->cookie(DeviceLock::clearCookie());
    }
}
