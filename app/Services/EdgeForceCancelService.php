<?php

namespace App\Services;

use App\Models\EdgeDevice;
use App\Models\ProgramAuditLog;

class EdgeForceCancelService
{
    public function __construct(private readonly ProgramLockService $lockService) {}

    /**
     * Force-cancel an unsynced session for an unreachable device.
     *
     * Requirements (§8.7):
     *  - Device must have session_active = true
     *  - Device must be offline or stale (last_seen_at > 1 hour ago or null)
     *
     * @throws \InvalidArgumentException for invalid precondition
     */
    public function cancel(EdgeDevice $device, int $adminUserId): void
    {
        if (! $device->session_active) {
            throw new \InvalidArgumentException('Device does not have an active session.');
        }

        $status = $device->getStatus();
        if (! in_array($status, ['offline', 'stale'])) {
            throw new \InvalidArgumentException('Force cancel is only allowed for offline or stale devices.');
        }

        if ($device->assigned_program_id !== null) {
            $this->lockService->unlock($device->assignedProgram);

            ProgramAuditLog::create([
                'program_id'    => $device->assigned_program_id,
                'staff_user_id' => $adminUserId,
                'action'        => 'edge_session_force_cancelled',
            ]);
        }

        $device->update([
            'session_active'     => false,
            'force_cancelled_at' => now(),
            'dump_requested'     => false,
        ]);
    }
}
