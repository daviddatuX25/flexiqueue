<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EdgeDevice;
use App\Models\Program;
use App\Models\Site;
use App\Services\EdgeForceCancelService;
use App\Services\EdgePairingService;
use App\Services\ProgramLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EdgeDeviceController extends Controller
{
    public function __construct(
        private readonly EdgePairingService $pairingService,
        private readonly ProgramLockService $lockService,
        private readonly EdgeForceCancelService $forceCancelService,
    ) {}

    /**
     * GET /api/admin/sites/{site}/edge-devices
     */
    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $devices = EdgeDevice::where('site_id', $site->id)
            ->whereNull('revoked_at')
            ->with('assignedProgram:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (EdgeDevice $d) => $this->deviceResource($d));

        $maxDevices = (int) ($site->edge_settings['max_edge_devices'] ?? 0);

        return response()->json([
            'devices'     => $devices,
            'slots_used'  => $devices->count(),
            'slots_total' => $maxDevices,
        ]);
    }

    /**
     * POST /api/admin/sites/{site}/edge-devices/pairing-code
     */
    public function generatePairingCode(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        if (! $this->pairingService->canGenerateCode($site->id)) {
            return response()->json([
                'message' => 'Site has reached its edge device limit. Revoke an existing device or ask a super admin to increase the limit.',
            ], 422);
        }

        $plainCode = $this->pairingService->generateCode($site->id, $validated['name']);

        return response()->json([
            'code'       => $plainCode,
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ]);
    }

    /**
     * PUT /api/admin/edge-devices/{device}
     */
    public function update(Request $request, EdgeDevice $device): JsonResponse
    {
        $this->authorizeSiteAccess($request, $device->site);

        $validated = $request->validate([
            'assigned_program_id'     => ['nullable', 'integer', 'exists:programs,id'],
            'sync_mode'               => ['required', 'in:auto,end_of_event'],
            'supervisor_admin_access' => ['required', 'boolean'],
        ]);

        $newProgramId = $validated['assigned_program_id'];

        // Validate new program belongs to this site before the transaction
        if ($newProgramId !== null) {
            $program = Program::findOrFail($newProgramId);

            if ((int) $program->site_id !== (int) $device->site_id) {
                return response()->json(['message' => 'Program does not belong to this site.'], 422);
            }
        }

        // E4.6: block program reassignment when session is active
        $changingProgram = $newProgramId !== $device->assigned_program_id;
        if ($changingProgram && $device->session_active) {
            return response()->json([
                'message' => 'Cannot reassign program while a session is active. End or dump the session first.',
            ], 422);
        }

        $lockConflict = DB::transaction(function () use ($device, $newProgramId, $program, &$validated) {
            // Re-read device inside transaction for fresh state
            $device->refresh();

            // Check new program lock inside transaction (atomic)
            if ($newProgramId !== null) {
                $freshProgram = Program::lockForUpdate()->find($newProgramId);
                if ($freshProgram === null) {
                    return 'not_found';
                }
                if ($freshProgram->edge_locked_by_device_id !== null
                    && $freshProgram->edge_locked_by_device_id !== $device->id) {
                    return 'locked';
                }
            }

            // Clear old lock
            if ($device->assigned_program_id !== null
                && $device->assigned_program_id !== $newProgramId) {
                $this->lockService->unlock($device->assignedProgram);
            }

            // Set new lock
            if ($newProgramId !== null) {
                $this->lockService->lock($device, $program);
            }

            $device->update([
                'assigned_program_id'     => $newProgramId,
                'sync_mode'               => $validated['sync_mode'],
                'supervisor_admin_access' => $validated['supervisor_admin_access'],
            ]);

            return null;
        });

        if ($lockConflict === 'locked') {
            return response()->json(['message' => 'Program is already assigned to another edge device.'], 422);
        }
        if ($lockConflict === 'not_found') {
            return response()->json(['message' => 'Program not found.'], 422);
        }

        return response()->json(['device' => $this->deviceResource($device->fresh(['assignedProgram']))]);
    }

    /**
     * DELETE /api/admin/edge-devices/{device}
     */
    public function revoke(Request $request, EdgeDevice $device): JsonResponse
    {
        $this->authorizeSiteAccess($request, $device->site);

        if ($device->assigned_program_id !== null) {
            $this->lockService->unlock($device->assignedProgram);
        }

        $device->update([
            'revoked_at'          => now(),
            'assigned_program_id' => null,
        ]);

        return response()->json(['message' => 'Device revoked.']);
    }

    /**
     * POST /api/admin/edge-devices/{device}/dump
     */
    public function dump(Request $request, EdgeDevice $device): JsonResponse
    {
        $this->authorizeSiteAccess($request, $device->site);

        if (! $device->session_active) {
            return response()->json(['message' => 'Device has no active session.'], 422);
        }

        $device->update(['dump_requested' => true]);

        return response()->json(['message' => 'Dump signal sent.']);
    }

    /**
     * POST /api/admin/edge-devices/{device}/force-cancel
     */
    public function forceCancel(Request $request, EdgeDevice $device): JsonResponse
    {
        $this->authorizeSiteAccess($request, $device->site);

        try {
            $this->forceCancelService->cancel($device, $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Session force-cancelled.']);
    }

    // ── private ───────────────────────────────────────────────────────

    private function authorizeSiteAccess(Request $request, Site $site): void
    {
        $user = $request->user();

        if ($user->can('platform.manage')) {
            return;
        }

        if ((int) $user->site_id !== (int) $site->id) {
            abort(403, 'Forbidden.');
        }
    }

    private function deviceResource(EdgeDevice $device): array
    {
        return [
            'id'                      => $device->id,
            'name'                    => $device->name,
            'status'                  => $device->getStatus(),
            'sync_mode'               => $device->sync_mode,
            'supervisor_admin_access' => $device->supervisor_admin_access,
            'assigned_program_id'     => $device->assigned_program_id,
            'assigned_program_name'   => $device->assignedProgram?->name,
            'session_active'          => $device->session_active,
            'last_seen_at'            => $device->last_seen_at?->toIso8601String(),
            'last_synced_at'          => $device->last_synced_at?->toIso8601String(),
            'paired_at'               => $device->paired_at?->toIso8601String(),
            'app_version'             => $device->app_version,
            'update_status'           => $device->update_status,
        ];
    }
}
