<?php

namespace App\Services;

use App\Models\Program;
use App\Models\User;
use App\Support\PermissionCatalog;

/**
 * Approve device unlock / device authorization QR flows for a program.
 *
 * @see docs/plans/RBAC_POLICY_CLEANUP.md
 */
class ProgramDeviceApprovalService
{
    public function __construct(
        private RbacContextService $rbacContextService
    ) {}

    public function canApproveForProgram(User $user, Program $program): bool
    {
        if ($user->can(PermissionCatalog::PLATFORM_MANAGE)) {
            return true;
        }

        $site = $program->site;
        if ($user->site_id !== null && (int) $user->site_id === (int) $program->site_id) {
            if ($this->rbacContextService->hasPermissionInContext($user, PermissionCatalog::ADMIN_MANAGE, $site, $program)) {
                return true;
            }
        }

        if ($this->rbacContextService->canInProgramTeamOnly($user, PermissionCatalog::PROGRAMS_SUPERVISE, $program)) {
            return true;
        }

        return $user->can(PermissionCatalog::PROGRAMS_SUPERVISE)
            && $user->isSupervisorForProgram($program->id);
    }
}
