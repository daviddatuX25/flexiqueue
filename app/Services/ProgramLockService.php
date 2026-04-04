<?php

namespace App\Services;

use App\Models\EdgeDevice;
use App\Models\Program;

class ProgramLockService
{
    public function lock(EdgeDevice $device, Program $program): void
    {
        Program::where('id', $program->id)
            ->update(['edge_locked_by_device_id' => $device->id]);
    }

    public function unlock(Program $program): void
    {
        Program::where('id', $program->id)
            ->update(['edge_locked_by_device_id' => null]);
    }

    public function isLockedByOtherDevice(Program $program, EdgeDevice $device): bool
    {
        $fresh = $program->fresh();
        return $fresh->edge_locked_by_device_id !== null
            && (int) $fresh->edge_locked_by_device_id !== (int) $device->id;
    }
}
