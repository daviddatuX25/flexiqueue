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
        if ($user->can(PermissionCatalog::ADMIN_MANAGE) || $user->can(PermissionCatalog::PLATFORM_MANAGE)) {
            return true;
        }

        return $user->isSupervisorForAnyProgram();
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

        return $user->isSupervisorForProgram($program->id);
    }

    /**
     * Skip interactive supervisor PIN/QR for call override when user is already elevated (admin / platform / supervisor).
     *
     * When $program is set (session flows), bypass only if this user supervises that program (pivot or program-team).
     * When $program is null, do not bypass — caller must supply program context for supervisor checks.
     */
    public function mayBypassSupervisorInteractiveAuth(User $user, ?Program $program = null): bool
    {
        if ($user->can(PermissionCatalog::ADMIN_MANAGE) || $user->can(PermissionCatalog::PLATFORM_MANAGE)) {
            return true;
        }

        if ($program === null) {
            return false;
        }

        return $user->isSupervisorForProgram($program->id);
    }

    /**
     * Force-complete / override without supervisor proof: site admin, platform, or supervisor (pivot/program-team).
     */
    public function mayForceCompleteOrOverrideWithoutSupervisorProof(User $user, ?Program $program = null): bool
    {
        return $this->mayBypassSupervisorInteractiveAuth($user, $program);
    }
}
