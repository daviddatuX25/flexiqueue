<?php

namespace App\Services;

use App\Models\Program;
use App\Models\User;
use App\Support\PermissionCatalog;

/**
 * Shared rules for staff flows without an assigned station (program picker) and supervisor PIN bypass.
 *
 * @see docs/plans/RBAC_POLICY_CLEANUP.md
 */
class StaffProgramAccessService
{
    public function __construct(
        private RbacContextService $rbacContextService
    ) {}

    /**
     * User may use session program context (bind / station list) when no station is assigned.
     */
    public function mayUseProgramPickerWithoutAssignedStation(User $user): bool
    {
        return $user->can(PermissionCatalog::ADMIN_MANAGE)
            || $user->can(PermissionCatalog::PLATFORM_MANAGE)
            || ($user->can(PermissionCatalog::PROGRAMS_SUPERVISE) && $user->isSupervisorForAnyProgram());
    }

    /**
     * User may bind or list stations for this program when unassigned (same as SessionPolicy intent).
     */
    public function mayAccessProgramWhenUnassigned(User $user, Program $program): bool
    {
        $site = $program->site;

        if ($this->rbacContextService->hasPermissionInContext($user, PermissionCatalog::ADMIN_MANAGE, $site, $program)) {
            return true;
        }

        if ($user->can(PermissionCatalog::PLATFORM_MANAGE)) {
            return true;
        }

        if ($this->rbacContextService->canInProgramTeamOnly($user, PermissionCatalog::PROGRAMS_SUPERVISE, $program)) {
            return true;
        }

        return $user->can(PermissionCatalog::PROGRAMS_SUPERVISE)
            && $user->isSupervisorForProgram($program->id);
    }

    /**
     * Skip interactive supervisor PIN/QR for call override when user is already elevated (admin / platform / supervisor).
     */
    public function mayBypassSupervisorInteractiveAuth(User $user): bool
    {
        return $user->can(PermissionCatalog::ADMIN_MANAGE)
            || $user->can(PermissionCatalog::PLATFORM_MANAGE)
            || ($user->can(PermissionCatalog::PROGRAMS_SUPERVISE) && $user->isSupervisorForAnyProgram());
    }

    /**
     * Force-complete / override without supervisor proof: site admin, platform, or legacy supervisor.
     */
    public function mayForceCompleteOrOverrideWithoutSupervisorProof(User $user): bool
    {
        return $this->mayBypassSupervisorInteractiveAuth($user);
    }
}
