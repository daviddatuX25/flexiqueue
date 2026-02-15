<?php

namespace App\Policies;

use App\Models\Station;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Per 05-SECURITY-CONTROLS §3.3: staff can only view their assigned station's queue.
 */
class StationPolicy
{
    /**
     * Staff can view a station's queue only if assigned to that station. Admin/supervisor can view any.
     * Per 08-API-SPEC-PHASE1 §4.1: 403 message "You are not assigned to this station."
     */
    public function view(User $user, Station $station): Response
    {
        if ($user->isAdmin() || $user->isSupervisorForProgram($station->program_id)) {
            return Response::allow();
        }

        if ($user->assigned_station_id === $station->id) {
            return Response::allow();
        }

        return Response::deny('You are not assigned to this station.');
    }
}
