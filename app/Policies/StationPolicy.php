<?php

namespace App\Policies;

use App\Models\Station;
use App\Models\User;
use App\Services\RbacContextService;
use App\Services\StaffAssignmentService;
use App\Support\PermissionCatalog;
use Illuminate\Auth\Access\Response;

/**
 * Per 05-SECURITY-CONTROLS §3.3: staff can only view their assigned station's queue.
 */
class StationPolicy
{
    public function __construct(
        private StaffAssignmentService $staffAssignmentService,
        private RbacContextService $rbacContextService
    ) {}

    /**
     * Staff can view a station's queue only if assigned to that station. Admin/supervisor can view any.
     * Per 08-API-SPEC-PHASE1 §4.1: 403 message "You are not assigned to this station."
     */
    public function view(User $user, Station $station): Response
    {
        $program = $station->program;
        $site = $program?->site;

        if ($this->rbacContextService->hasPermissionInContext($user, PermissionCatalog::ADMIN_MANAGE, $site, $program)) {
            return Response::allow();
        }

        if ($user->can(PermissionCatalog::PLATFORM_MANAGE)) {
            return Response::allow();
        }

        if ($program && $user->isSupervisorForProgram($station->program_id)) {
            return Response::allow();
        }

        $assigned = $this->staffAssignmentService->getStationForUser($user, $station->program_id);
        if ($assigned && $assigned->id === $station->id) {
            return Response::allow();
        }

        return Response::deny('You are not assigned to this station.');
    }

    /**
     * Toggling priority-first on a station: admin / platform / program supervisor only — not line staff
     * assigned to the station (they may view queue but must not change this setting).
     */
    public function managePriority(User $user, Station $station): bool
    {
        $program = $station->program;
        $site = $program?->site;

        if ($this->rbacContextService->hasPermissionInContext($user, PermissionCatalog::ADMIN_MANAGE, $site, $program)) {
            return true;
        }

        if ($user->can(PermissionCatalog::PLATFORM_MANAGE)) {
            return true;
        }

        if ($program && $user->isSupervisorForProgram($station->program_id)) {
            return true;
        }

        return false;
    }
}
