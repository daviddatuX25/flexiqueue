<?php

namespace App\Http\Controllers\Api\Edge;

use App\Http\Controllers\Controller;
use App\Models\EdgeDevice;
use App\Services\ProgramPackageExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeartbeatController extends Controller
{
    public function __construct(
        private readonly ProgramPackageExporter $packageExporter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edge_device');

        // If device was revoked, return signal without updating state
        if ($device->isRevoked()) {
            return response()->json(['revoked' => true]);
        }

        $validated = $request->validate([
            'session_active'  => ['required', 'boolean'],
            'sync_mode'       => ['required', 'in:auto,end_of_event'],
            'last_synced_at'  => ['nullable', 'date'],
            'package_version' => ['nullable', 'string', 'max:255'],
            'app_version'     => ['nullable', 'string', 'max:50'],
        ]);

        $updates = [
            'last_seen_at'   => now(),
            'session_active' => $validated['session_active'],
        ];

        if (! empty($validated['app_version'])) {
            $updates['app_version'] = $validated['app_version'];
        }

        if (! empty($validated['last_synced_at'])) {
            $updates['last_synced_at'] = $validated['last_synced_at'];
        }

        $latestAppVersion = config('flexiqueue.latest_edge_app_version');
        if (! empty($validated['app_version']) && $latestAppVersion !== null) {
            $updateAvailable = version_compare($validated['app_version'], $latestAppVersion, '<');
            $updates['update_status'] = $updateAvailable ? 'update_available' : 'up_to_date';
        }

        $device->update($updates);

        $isVoided = $device->force_cancelled_at !== null;

        $packageStale = false;
        if ($device->assigned_program_id !== null && ! empty($validated['package_version'])) {
            $program = $device->assignedProgram;
            $site = $device->site;
            $currentVersion = $this->packageExporter->computePackageVersion($program, $site);
            $packageStale = $currentVersion !== $validated['package_version'];
        }

        $updateAvailableResponse = false;
        if (! empty($validated['app_version']) && $latestAppVersion !== null) {
            $updateAvailableResponse = version_compare($validated['app_version'], $latestAppVersion, '<');
        }

        return response()->json([
            'revoked'                 => false,
            'sync_mode'               => $device->sync_mode,
            'supervisor_admin_access' => $device->supervisor_admin_access,
            'update_available'        => $updateAvailableResponse,
            'dump_session'            => (bool) $device->dump_requested,
            'session_voided'          => $isVoided,
            'voided_at'               => $isVoided ? $device->force_cancelled_at->toIso8601String() : null,
            'package_stale'           => $packageStale,
        ]);
    }
}
