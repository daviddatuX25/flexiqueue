<?php

namespace App\Policies;

use App\Models\Session;
use App\Models\User;
use App\Services\StaffAssignmentService;

/**
 * Per 05-SECURITY-CONTROLS §3.3: staff can only act on sessions at their assigned station.
 */
class SessionPolicy
{
    public function __construct(
        private StaffAssignmentService $staffAssignmentService
    ) {}

    /**
     * Staff can view a session only if it is at their assigned station (active or held). Admin/supervisor can view any.
     */
    public function view(User $user, Session $session): bool
    {
        if ($user->isAdmin() || $user->isSupervisorForProgram($session->program_id)) {
            return true;
        }

        $assigned = $this->staffAssignmentService->getStationForUser($user, $session->program_id);

        return $assigned !== null
            && ($session->current_station_id === $assigned->id || $session->holding_station_id === $assigned->id);
    }

    /**
     * Staff can update (transfer, complete, cancel, no-show, hold, resume) only at their assigned station (active or held).
     */
    public function update(User $user, Session $session): bool
    {
        if ($user->isAdmin() || $user->isSupervisorForProgram($session->program_id)) {
            return true;
        }

        $assigned = $this->staffAssignmentService->getStationForUser($user, $session->program_id);

        return $assigned !== null
            && ($session->current_station_id === $assigned->id || $session->holding_station_id === $assigned->id);
    }
}
