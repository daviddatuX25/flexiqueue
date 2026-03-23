<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\RbacTeam;
use App\Models\User;
use App\Support\PermissionCatalog;

/**
 * Supervisor UX: global direct grants for staff who supervise programs (dashboard + auth tools).
 * Primary roles are assigned via Spatie only ({@see User::assignGlobalRoleAndSyncProvisioning}).
 * R4: `programs.supervise` is only on program {@see RbacTeam}s — not granted globally here.
 */
class SpatieRbacSyncService
{
    public function syncUser(User $user): void
    {
        $previous = getPermissionsTeamId();
        setPermissionsTeamId(RbacTeam::GLOBAL_TEAM_ID);
        try {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $this->syncSupervisorDirectPermissions($user);
        } finally {
            setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * Staff supervisors need dashboard + auth tools without changing enum role.
     * Admins already receive these via the admin role bundle; revoke any stale direct grants.
     */
    public function syncSupervisorDirectPermissions(User $user): void
    {
        $perms = [
            PermissionCatalog::DASHBOARD_VIEW,
            PermissionCatalog::AUTH_SUPERVISOR_TOOLS,
        ];

        $previous = getPermissionsTeamId();
        setPermissionsTeamId(RbacTeam::GLOBAL_TEAM_ID);
        try {
            $user->unsetRelation('roles')->unsetRelation('permissions');

            if ($user->hasRole([UserRole::Admin->value, UserRole::SuperAdmin->value])) {
                $user->revokePermissionTo($perms);

                return;
            }

            if (! $user->hasRole(UserRole::Staff->value)) {
                return;
            }

            if ($user->isSupervisorForAnyProgram()) {
                $user->givePermissionTo($perms);
            } else {
                $user->revokePermissionTo($perms);
            }
        } finally {
            setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
