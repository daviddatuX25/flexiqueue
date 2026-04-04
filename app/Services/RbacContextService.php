<?php

namespace App\Services;

use App\Models\Program;
use App\Models\RbacTeam;
use App\Models\Site;
use App\Models\User;
use App\Support\PermissionCatalog;

/**
 * Effective permission = global team ∪ site team ∪ program team (OR).
 *
 * @see docs/architecture/PERMISSIONS-TEAMS-AND-UI.md §3
 */
class RbacContextService
{
    public function hasPermissionInContext(User $user, string $permission, ?Site $site, ?Program $program): bool
    {
        $previous = getPermissionsTeamId();
        try {
            setPermissionsTeamId(RbacTeam::GLOBAL_TEAM_ID);
            $user->unsetRelation('roles')->unsetRelation('permissions');
            if ($user->can($permission)) {
                return true;
            }

            if ($site !== null) {
                setPermissionsTeamId(RbacTeam::forSite($site)->id);
                $user->unsetRelation('roles')->unsetRelation('permissions');
                if ($user->can($permission)) {
                    return true;
                }
            }

            if ($program !== null) {
                setPermissionsTeamId(RbacTeam::forProgram($program)->id);
                $user->unsetRelation('roles')->unsetRelation('permissions');
                if ($user->can($permission)) {
                    return true;
                }
            }

            return false;
        } finally {
            setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * Check permission only in the program's RbacTeam (no global/site short-circuit).
     * Use for programs.supervise when Phase 6 scoped grants must apply without legacy pivot.
     */
    public function canInProgramTeamOnly(User $user, string $permission, Program $program): bool
    {
        $previous = getPermissionsTeamId();
        try {
            setPermissionsTeamId(RbacTeam::forProgram($program)->id);
            $user->unsetRelation('roles')->unsetRelation('permissions');

            return $user->can($permission);
        } finally {
            setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * True if the user has {@see PermissionCatalog::PROGRAMS_SUPERVISE} on any active program
     * {@see RbacTeam} in their site scope (pivot-independent).
     */
    public function hasProgramTeamSuperviseOnAnyActiveProgramInUserScope(User $user): bool
    {
        $query = Program::query()->where('is_active', true);
        if ($user->site_id !== null) {
            $query->where('site_id', $user->site_id);
        } else {
            $query->whereNull('site_id');
        }

        foreach ($query->cursor() as $program) {
            if ($this->canInProgramTeamOnly($user, PermissionCatalog::PROGRAMS_SUPERVISE, $program)) {
                return true;
            }
        }

        return false;
    }
}
