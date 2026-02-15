<?php

namespace App\Policies;

use App\Models\Session;
use App\Models\User;

/**
 * Per 05-SECURITY-CONTROLS §3.3: staff can only act on sessions at their assigned station.
 */
class SessionPolicy
{
    /**
     * Staff can view a session only if it is at their assigned station. Admin/supervisor can view any.
     */
    public function view(User $user, Session $session): bool
    {
        if ($user->isAdmin() || $user->isSupervisorForProgram($session->program_id)) {
            return true;
        }

        return $user->assigned_station_id !== null
            && $session->current_station_id === $user->assigned_station_id;
    }

    /**
     * Staff can update (transfer, complete, cancel, no-show) only at their assigned station.
     */
    public function update(User $user, Session $session): bool
    {
        if ($user->isAdmin() || $user->isSupervisorForProgram($session->program_id)) {
            return true;
        }

        return $user->assigned_station_id !== null
            && $session->current_station_id === $user->assigned_station_id;
    }
}
