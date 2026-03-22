<?php

namespace App\Policies;

use App\Models\Session;
use App\Models\User;
use App\Services\RbacContextService;
use App\Services\StaffAssignmentService;
use App\Support\PermissionCatalog;

/**
 * Per 05-SECURITY-CONTROLS §3.3: staff can only act on sessions at their assigned station.
 */
class SessionPolicy
{
    public function __construct(
        private StaffAssignmentService $staffAssignmentService,
        private RbacContextService $rbacContextService
    ) {}

    /**
     * Staff can view a session only if it is at their assigned station (active or held). Admin/supervisor can view any.
     */
    public function view(User $user, Session $session): bool
    {
        $program = $session->program;
        $site = $program?->site;

        if ($this->rbacContextService->hasPermissionInContext($user, PermissionCatalog::ADMIN_MANAGE, $site, $program)) {
            return true;
        }

        if ($user->can(PermissionCatalog::PLATFORM_MANAGE)) {
            return true;
        }

        if ($program && $this->rbacContextService->canInProgramTeamOnly($user, PermissionCatalog::PROGRAMS_SUPERVISE, $program)) {
            return true;
        }

        if ($user->can(PermissionCatalog::PROGRAMS_SUPERVISE) && $user->isSupervisorForProgram($session->program_id)) {
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
        $program = $session->program;
        $site = $program?->site;

        if ($this->rbacContextService->hasPermissionInContext($user, PermissionCatalog::ADMIN_MANAGE, $site, $program)) {
            return true;
        }

        if ($user->can(PermissionCatalog::PLATFORM_MANAGE)) {
            return true;
        }

        if ($program && $this->rbacContextService->canInProgramTeamOnly($user, PermissionCatalog::PROGRAMS_SUPERVISE, $program)) {
            return true;
        }

        if ($user->can(PermissionCatalog::PROGRAMS_SUPERVISE) && $user->isSupervisorForProgram($session->program_id)) {
            return true;
        }

        $assigned = $this->staffAssignmentService->getStationForUser($user, $session->program_id);

        return $assigned !== null
            && ($session->current_station_id === $assigned->id || $session->holding_station_id === $assigned->id);
    }
}
