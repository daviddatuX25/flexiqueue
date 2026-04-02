<?php

namespace App\Http\Controllers\Api\Edge;

use App\Http\Controllers\Controller;
use App\Models\EdgeDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeartbeatController extends Controller
{
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

        $device->update($updates);

        return response()->json([
            'revoked'                 => false,
            'sync_mode'               => $device->sync_mode,
            'supervisor_admin_access' => $device->supervisor_admin_access,
            'update_available'        => false,
        ]);
    }
}
