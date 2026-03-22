<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\RbacTeam;
use App\Models\User;
use App\Support\PermissionCatalog;
use Spatie\Permission\Models\Role;

/**
 * Keeps Spatie roles/permissions aligned with User.role enum and program_supervisors (supervisor UX).
 * Routes use Spatie `permission:` middleware; supervisor direct grants stay in sync here.
 */
class SpatieRbacSyncService
{
    public function syncUser(User $user): void
    {
        $previous = getPermissionsTeamId();
        setPermissionsTeamId(RbacTeam::GLOBAL_TEAM_ID);
        try {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $this->syncRoleFromEnum($user);
            $this->syncSupervisorDirectPermissions($user);
        } finally {
            setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * One Spatie role per user, mirroring users.role.
     */
    public function syncRoleFromEnum(User $user): void
    {
        $name = $user->role instanceof UserRole ? $user->role->value : (string) $user->role;
        $guard = PermissionCatalog::guardName();
        Role::findOrCreate($name, $guard);
        $user->syncRoles([$name]);
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
            PermissionCatalog::PROGRAMS_SUPERVISE,
        ];

        $previous = getPermissionsTeamId();
        setPermissionsTeamId(RbacTeam::GLOBAL_TEAM_ID);
        try {
            $user->unsetRelation('roles')->unsetRelation('permissions');

            if ($user->role === UserRole::Admin || $user->role === UserRole::SuperAdmin) {
                $user->revokePermissionTo($perms);

                return;
            }

            if ($user->role !== UserRole::Staff) {
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
