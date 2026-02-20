<?php

namespace App\Services;

use App\Models\Program;
use App\Models\ProgramAuditLog;
use App\Models\ProgramStationAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Per 08-API-SPEC-PHASE1 §5.1: activate (deactivates current), deactivate (no active sessions), delete (no sessions).
 * Per flexiqueue-loo: program session start/stop recorded in program_audit_log.
 */
class ProgramService
{
    /**
     * Activate this program; deactivate the current active program.
     */
    public function activate(Program $program): Program
    {
        return DB::transaction(function () use ($program) {
            $previousActive = Program::where('is_active', true)->where('id', '!=', $program->id)->first();
            Program::where('is_active', true)->where('id', '!=', $program->id)->update(['is_active' => false, 'is_paused' => false]);
            $program->update(['is_active' => true, 'is_paused' => false]);

            $staffUserId = Auth::id();
            if ($staffUserId && $previousActive) {
                ProgramAuditLog::create([
                    'program_id' => $previousActive->id,
                    'staff_user_id' => $staffUserId,
                    'action' => 'session_stop',
                ]);
            }
            if ($staffUserId) {
                ProgramAuditLog::create([
                    'program_id' => $program->id,
                    'staff_user_id' => $staffUserId,
                    'action' => 'session_start',
                ]);
            }

            $this->syncAssignedStationForProgram($program);

            return $program->fresh();
        });
    }

    /**
     * Sync users.assigned_station_id for all staff based on ProgramStationAssignment for this program.
     * Staff with assignment get their station; staff whose assigned_station_id is in this program
     * but have no assignment get nulled.
     */
    private function syncAssignedStationForProgram(Program $program): void
    {
        foreach (ProgramStationAssignment::where('program_id', $program->id)->get() as $a) {
            User::where('id', $a->user_id)->update(['assigned_station_id' => $a->station_id]);
        }

        $stationIds = $program->stations()->pluck('id')->all();
        $assignedUserIds = ProgramStationAssignment::where('program_id', $program->id)->pluck('user_id')->all();

        User::where('role', 'staff')
            ->whereIn('assigned_station_id', $stationIds)
            ->whereNotIn('id', $assignedUserIds)
            ->update(['assigned_station_id' => null]);
    }

    /**
     * Pause program. Queue times do not count while paused.
     */
    public function pause(Program $program): Program
    {
        if (! $program->is_active) {
            throw new \InvalidArgumentException('Only active programs can be paused.');
        }

        $program->update(['is_paused' => true]);

        return $program->fresh();
    }

    /**
     * Resume program. Queue times count again.
     */
    public function resume(Program $program): Program
    {
        if (! $program->is_active) {
            throw new \InvalidArgumentException('Only active programs can be resumed.');
        }

        $program->update(['is_paused' => false]);

        return $program->fresh();
    }

    /**
     * Deactivate program. Fails if any active (waiting/called/serving) sessions exist.
     */
    public function deactivate(Program $program): Program
    {
        $hasActiveSessions = $program->queueSessions()->active()->exists();

        if ($hasActiveSessions) {
            throw new \InvalidArgumentException('Cannot deactivate: program has active sessions.');
        }

        $program->update(['is_active' => false, 'is_paused' => false]);

        $staffUserId = Auth::id();
        if ($staffUserId) {
            ProgramAuditLog::create([
                'program_id' => $program->id,
                'staff_user_id' => $staffUserId,
                'action' => 'session_stop',
            ]);
        }

        return $program->fresh();
    }

    /**
     * Delete program. Fails if any sessions exist (any status).
     */
    public function delete(Program $program): void
    {
        if ($program->queueSessions()->exists()) {
            throw new \InvalidArgumentException('Cannot delete: program has sessions.');
        }

        $program->delete();
    }
}
