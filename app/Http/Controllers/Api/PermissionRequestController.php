<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Models\PermissionRequest;
use App\Models\Program;
use App\Models\Session;
use App\Services\PermissionRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Permission requests: staff creates; supervisor/admin approves or rejects.
 */
class PermissionRequestController extends Controller
{
    public function __construct(
        private PermissionRequestService $permissionRequestService
    ) {}

    /**
     * Create a permission request (staff).
     * Per TRACK-OVERRIDES-REFACTOR: override uses target_track_id + optional custom_steps.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'integer', 'exists:queue_sessions,id'],
            'action_type' => ['required', 'string', 'in:override,force_complete'],
            'reason' => [
                Rule::when(
                    $request->input('action_type') === 'force_complete'
                    || ($request->input('action_type') === 'override' && (
                        $request->boolean('is_custom')
                        || (is_array($request->input('custom_steps')) && count($request->input('custom_steps') ?? []) > 0)
                    )),
                    ['required', 'string', 'min:1'],
                    ['nullable', 'string']
                ),
            ],
            'target_track_id' => ['nullable', 'integer', 'exists:service_tracks,id'],
            'is_custom' => ['nullable', 'boolean'],
            'custom_steps' => ['nullable', 'array'],
            'custom_steps.*' => ['integer', 'exists:stations,id'],
        ]);

        if ($validated['action_type'] === 'override' && empty($validated['is_custom']) && empty($validated['target_track_id'])) {
            return response()->json(['message' => 'Override requires target track or custom path.'], 422);
        }

        $user = $request->user();
        $session = Session::findOrFail($validated['session_id']);
        $customSteps = $this->sanitizeCustomSteps($validated['custom_steps'] ?? null);

        $targetTrackId = ! empty($validated['is_custom']) ? null : ($validated['target_track_id'] ?? null);

        $pr = $this->permissionRequestService->create(
            $session,
            $validated['action_type'],
            $user->id,
            $validated['reason'] ?? '',
            null,
            $targetTrackId,
            $customSteps
        );

        return response()->json([
            'id' => $pr->id,
            'request_token' => $pr->request_token,
            'status' => $pr->status,
            'message' => 'Request sent. Waiting for supervisor approval.',
        ], 201);
    }

    /**
     * @return array<int>|null
     */
    private function sanitizeCustomSteps(mixed $value): ?array
    {
        if (! is_array($value) || count($value) === 0) {
            return null;
        }

        return array_values(array_map('intval', array_filter($value, fn ($v) => is_numeric($v))));
    }

    /**
     * Approve a permission request (supervisor/admin).
     * For override: optional target_track_id or custom_steps to use (overrides request values).
     * When approving via QR scan, request_token must be provided and must match the stored token.
     */
    public function approve(Request $request, PermissionRequest $permission_request): JsonResponse
    {
        $user = $request->user();
        $session = $permission_request->session;
        $program = $session?->program;
        if (! $program) {
            return response()->json(['message' => 'Session or program not found.'], 403);
        }

        $canApprove = ($user->isAdmin() && $user->site_id === $program->site_id)
            || $user->isSupervisorForProgram($program->id);
        if (! $canApprove) {
            return response()->json(['message' => 'You may only approve permission requests for your program or site.'], 403);
        }

        $validated = $request->validate([
            'request_token' => ['nullable', 'string', 'size:64'],
            'target_track_id' => ['nullable', 'integer', 'exists:service_tracks,id'],
            'custom_steps' => ['nullable', 'array'],
            'custom_steps.*' => ['integer', 'exists:stations,id'],
        ]);

        $requestToken = $validated['request_token'] ?? null;
        if ($requestToken !== null && ! hash_equals($permission_request->request_token ?? '', $requestToken)) {
            return response()->json(['message' => 'Invalid or expired QR.'], 403);
        }

        $approveTargetTrackId = $validated['target_track_id'] ?? null;
        $approveCustomSteps = $this->sanitizeCustomSteps($validated['custom_steps'] ?? null);

        try {
            $result = $this->permissionRequestService->approve(
                $permission_request,
                $user->id,
                $approveTargetTrackId,
                $approveCustomSteps
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], (int) ($e->getCode() ?: 409));
        }

        return response()->json($result);
    }

    /**
     * Reject a permission request (supervisor/admin).
     * Per TRACK-OVERRIDES-REFACTOR: optional reassign_track_id or custom_steps to reassign session.
     */
    public function reject(Request $request, PermissionRequest $permission_request): JsonResponse
    {
        $user = $request->user();
        $session = $permission_request->session;
        $program = $session?->program;
        if (! $program) {
            return response()->json(['message' => 'Session or program not found.'], 403);
        }

        $canApprove = ($user->isAdmin() && $user->site_id === $program->site_id)
            || $user->isSupervisorForProgram($program->id);
        if (! $canApprove) {
            return response()->json(['message' => 'You may only reject permission requests for your program or site.'], 403);
        }

        $validated = $request->validate([
            'reassign_track_id' => ['nullable', 'integer', 'exists:service_tracks,id'],
            'custom_steps' => ['nullable', 'array'],
            'custom_steps.*' => ['integer', 'exists:stations,id'],
        ]);

        $customSteps = $this->sanitizeCustomSteps($validated['custom_steps'] ?? null);

        try {
            $result = $this->permissionRequestService->reject(
                $permission_request,
                $user->id,
                $validated['reassign_track_id'] ?? null,
                $customSteps
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], (int) ($e->getCode() ?: 409));
        }

        if ($result !== null) {
            return response()->json([
                'message' => 'Request rejected. Session reassigned.',
                'session' => SessionResource::make($result['session'])->resolve(),
            ]);
        }

        return response()->json(['message' => 'Request rejected.']);
    }

    /**
     * Cancel a pending permission request (requester only, e.g. on timeout).
     */
    public function cancel(Request $request, PermissionRequest $permission_request): JsonResponse
    {
        try {
            $this->permissionRequestService->cancel($permission_request, $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            $code = (int) ($e->getCode() ?: 409);

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return response()->json(['message' => 'Request cancelled.'], 200);
    }
}
