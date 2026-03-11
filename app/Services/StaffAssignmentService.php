<?php

namespace App\Services;

use App\Models\ProgramStationAssignment;
use App\Models\Station;
use App\Models\User;

/**
 * Resolves a user's assigned station for a given program. Per REFACTORING-ISSUE-LIST.md Issue 9:
 * logic moved from User::assignedStationForProgram().
 */
class StaffAssignmentService
{
    /**
     * Resolve assigned station for a user in a program. Uses program_station_assignments first,
     * with fallback to assigned_station_id when that station belongs to the given program.
     */
    public function getStationForUser(User $user, int $programId): ?Station
    {
        $assignment = ProgramStationAssignment::query()
            ->where('program_id', $programId)
            ->where('user_id', $user->id)
            ->with('station')
            ->first();

        if ($assignment) {
            return $assignment->station;
        }

        if ($user->assigned_station_id) {
            $station = Station::find($user->assigned_station_id);
            if ($station && (int) $station->program_id === $programId) {
                return $station;
            }
        }

        return null;
    }
}
