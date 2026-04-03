<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EdgeDevice;
use App\Models\Program;
use App\Models\Site;
use App\Services\EdgePairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EdgeDeviceController extends Controller
{
    public function __construct(private readonly EdgePairingService $pairingService) {}

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

        if ($newProgramId !== null) {
            $program = Program::findOrFail($newProgramId);

            if ((int) $program->site_id !== (int) $device->site_id) {
                return response()->json(['message' => 'Program does not belong to this site.'], 422);
            }

            if ($program->edge_locked_by_device_id !== null
                && $program->edge_locked_by_device_id !== $device->id) {
                return response()->json(['message' => 'Program is already assigned to another edge device.'], 422);
            }
        }

        // Clear old lock if changing assignment
        if ($device->assigned_program_id !== null
            && $device->assigned_program_id !== $newProgramId) {
            Program::where('id', $device->assigned_program_id)
                ->update(['edge_locked_by_device_id' => null]);
        }

        // Set new lock
        if ($newProgramId !== null) {
            Program::where('id', $newProgramId)
                ->update(['edge_locked_by_device_id' => $device->id]);
        }

        $device->update([
            'assigned_program_id'     => $newProgramId,
            'sync_mode'               => $validated['sync_mode'],
            'supervisor_admin_access' => $validated['supervisor_admin_access'],
        ]);

        return response()->json(['device' => $this->deviceResource($device->fresh(['assignedProgram']))]);
    }

    /**
     * DELETE /api/admin/edge-devices/{device}
     */
    public function revoke(Request $request, EdgeDevice $device): JsonResponse
    {
        $this->authorizeSiteAccess($request, $device->site);

        if ($device->assigned_program_id !== null) {
            Program::where('id', $device->assigned_program_id)
                ->update(['edge_locked_by_device_id' => null]);
        }

        $device->update([
            'revoked_at'          => now(),
            'assigned_program_id' => null,
        ]);

        return response()->json(['message' => 'Device revoked.']);
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
        ];
    }
}
