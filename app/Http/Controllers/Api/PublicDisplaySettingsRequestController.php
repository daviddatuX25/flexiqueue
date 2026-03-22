<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DisplaySettingsRequest;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public: create display settings request (device shows QR) and poll status.
 */
class PublicDisplaySettingsRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id' => ['required', 'integer', 'exists:programs,id'],
            'display_audio_muted' => ['sometimes', 'boolean'],
            'display_audio_volume' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'enable_display_hid_barcode' => ['sometimes', 'boolean'],
            'enable_public_triage_hid_barcode' => ['sometimes', 'boolean'],
            'enable_display_camera_scanner' => ['sometimes', 'boolean'],
            'enable_public_triage_camera_scanner' => ['sometimes', 'boolean'],
            'kiosk_hid_persistent_when_scan_modal_closed' => ['sometimes', 'boolean'],
        ]);

        $program = Program::findOrFail($validated['program_id']);
        if (! $program->is_active) {
            return response()->json(['message' => 'Program not active.'], 400);
        }

        $payload = array_intersect_key($validated, array_flip([
            'display_audio_muted', 'display_audio_volume',
            'enable_display_hid_barcode', 'enable_public_triage_hid_barcode',
            'enable_display_camera_scanner', 'enable_public_triage_camera_scanner',
            'kiosk_hid_persistent_when_scan_modal_closed',
        ]));

        $req = DisplaySettingsRequest::create([
            'program_id' => $program->id,
            'request_token' => str()->random(64),
            'status' => DisplaySettingsRequest::STATUS_PENDING,
            'settings_payload' => $payload,
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

        $req = DisplaySettingsRequest::find($id);
        if (! $req || ! hash_equals($req->request_token ?? '', $token)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(['status' => $req->status]);
    }

    /**
     * Cancel a pending request (e.g. when user navigates away). No auth; token required.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'request_token' => ['required', 'string', 'size:64'],
        ]);

        $req = DisplaySettingsRequest::find($id);
        if (! $req || ! hash_equals($req->request_token ?? '', $validated['request_token'])) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (! $req->isPending()) {
            return response()->json(['message' => 'Request already handled.'], 409);
        }

        $req->update(['status' => DisplaySettingsRequest::STATUS_CANCELLED]);

        return response()->json(['message' => 'Request cancelled.'], 200);
    }
}
