<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Events\ProgramStatusChanged;
use App\Events\StaffAvailabilityUpdated;
use App\Models\Program;
use App\Models\ProgramAuditLog;
use App\Models\ProgramStationAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Per 08-API-SPEC-PHASE1 §5.1: activate, deactivate (no active sessions), delete (no sessions).
 * Per central-edge-v2-final Phase A: activate() no longer deactivates others; use activateExclusive() for single-program (e.g. Pi).
 * Per flexiqueue-loo: program session start/stop recorded in program_audit_log.
 * Per ISSUES-ELABORATION §16: pre-session checkers before activate.
 */
class ProgramService
{
    /**
     * Check if program can be activated (has stations, processes with stations, staff assigned, tracks).
     * Returns ['can_activate' => bool, 'missing' => string[]].
     */
    public function canActivate(Program $program): array
    {
        $missing = [];

        if (! $program->stations()->exists()) {
            $missing[] = 'no_stations';
        }

        if (! $program->stations()->whereHas('processes')->exists()) {
            $missing[] = 'no_processes_with_stations';
        }

        if (! ProgramStationAssignment::where('program_id', $program->id)->exists()) {
            $missing[] = 'no_staff_assigned';
        }

        if (! $program->serviceTracks()->exists()) {
            $missing[] = 'no_tracks';
        }

        return [
            'can_activate' => empty($missing),
            'missing' => $missing,
        ];
    }

    /**
     * Activate this program. Does not deactivate other programs (multi-program foundation).
     * Per central-edge-v2-final Phase A: use activateExclusive() where single-program semantics are required (e.g. Pi import).
     */
    public function activate(Program $program): Program
    {
        if ($program->edge_locked_by_device_id !== null) {
            throw new \InvalidArgumentException(
                'This program is currently assigned to an edge device and cannot be started from central. Unassign the edge device first.'
            );
        }

        return DB::transaction(function () use ($program) {
            $program->update(['is_active' => true, 'is_paused' => false]);

            $staffUserId = Auth::id();
            if ($staffUserId) {
                ProgramAuditLog::create([
                    'program_id' => $program->id,
                    'staff_user_id' => $staffUserId,
                    'action' => 'session_start',
                ]);
            }

            $this->syncAssignedStationForProgram($program);
            $this->setAssignedStaffAvailability($program, User::AVAILABILITY_AWAY);

            return $program->fresh();
        });
    }

    /**
     * Activate this program and deactivate all others (single-program semantics, e.g. Pi after package import).
     * Fails if another program is active and still has clients in the queue (waiting/called/serving).
     */
    public function activateExclusive(Program $program): Program
    {
        if ($program->edge_locked_by_device_id !== null) {
            throw new \InvalidArgumentException(
                'This program is currently assigned to an edge device and cannot be started from central. Unassign the edge device first.'
            );
        }

        $previousActive = Program::where('is_active', true)->where('id', '!=', $program->id)->first();
        if ($previousActive && $previousActive->queueSessions()->active()->exists()) {
            throw new \InvalidArgumentException(
                'Another program is currently running and has clients in the queue. Stop that program\'s session first (or wait until the queue is empty) before starting this program.'
            );
        }

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
            $this->setAssignedStaffAvailability($program, User::AVAILABILITY_AWAY);

            return $program->fresh();
        });
    }

    /**
     * Count staff users (role=staff) who have station assignments in more than one active program.
     * Per central-edge follow-up: on program day, show warning when activating if any staff are in multiple active programs.
     */
    public function getStaffInMultipleActiveProgramsCount(): int
    {
        $activeProgramIds = Program::where('is_active', true)->pluck('id')->all();
        if (count($activeProgramIds) < 2) {
            return 0;
        }

        return (int) ProgramStationAssignment::query()
            ->whereIn('program_id', $activeProgramIds)
            ->whereHas('user', function ($q): void {
                User::withGlobalPermissionsTeam(fn () => $q->role(UserRole::Staff->value));
            })
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(DISTINCT program_id) > 1')
            ->count();
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

        User::withGlobalPermissionsTeam(fn () => User::query()
            ->role(UserRole::Staff->value)
            ->whereIn('assigned_station_id', $stationIds)
            ->whereNotIn('id', $assignedUserIds)
            ->update(['assigned_station_id' => null]));
    }

    /**
     * Update availability for staff assigned to this program's stations.
     * Optionally limit updates to specific source statuses.
     *
     * @param  list<string>|null  $fromStatuses
     */
    private function setAssignedStaffAvailability(Program $program, string $toStatus, ?array $fromStatuses = null): void
    {
        $staff = User::withGlobalPermissionsTeam(fn () => User::query()
            ->role(UserRole::Staff->value)
            ->whereIn('id', function ($q) use ($program) {
                $q->select('user_id')
                    ->from('program_station_assignments')
                    ->where('program_id', $program->id);
            })
            ->when($fromStatuses !== null, fn ($q) => $q->whereIn('availability_status', $fromStatuses))
            ->get(['id', 'name', 'availability_status']));

        foreach ($staff as $user) {
            if (($user->availability_status ?? User::AVAILABILITY_OFFLINE) === $toStatus) {
                continue;
            }

            $user->update([
                'availability_status' => $toStatus,
                'availability_updated_at' => now(),
            ]);

            broadcast(new StaffAvailabilityUpdated(
                $program->id,
                $user->id,
                $user->availability_status ?? User::AVAILABILITY_OFFLINE,
                $user->name ?? ''
            ));
        }
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
        $this->setAssignedStaffAvailability($program, User::AVAILABILITY_ON_BREAK, [User::AVAILABILITY_AVAILABLE]);

        broadcast(new ProgramStatusChanged($program->id, true, true));

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
        $this->setAssignedStaffAvailability($program, User::AVAILABILITY_AWAY, [User::AVAILABILITY_ON_BREAK]);

        broadcast(new ProgramStatusChanged($program->id, false, true));

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

        broadcast(new ProgramStatusChanged($program->id, false, false));

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
     * Per plan Step 5: invalidate all device authorizations for this program.
     */
    public function delete(Program $program): void
    {
        if ($program->queueSessions()->exists()) {
            throw new \InvalidArgumentException('Cannot delete: program has sessions.');
        }

        app(DeviceAuthorizationService::class)->revokeForProgram($program);
        $program->delete();
    }
}
