<?php

namespace App\Services;

use App\Models\Program;
use App\Models\RbacTeam;
use App\Models\User;
use App\Support\PermissionCatalog;
use Spatie\Permission\PermissionRegistrar;

/**
 * Authoritative Spatie grant for program supervision — `programs.supervise` on the program {@see RbacTeam}.
 * Admin add/remove supervisor and seeders use this; the `program_supervisors` table has been removed (R4).
 */
final class ProgramSupervisorGrantService
{
    public function grantProgramTeamSupervise(User $user, Program $program): void
    {
        $team = RbacTeam::forProgram($program);
        $previous = getPermissionsTeamId();
        setPermissionsTeamId($team->id);
        try {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->givePermissionTo(PermissionCatalog::PROGRAMS_SUPERVISE);
        } finally {
            setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function revokeProgramTeamSupervise(User $user, Program $program): void
    {
        $team = RbacTeam::forProgram($program);
        $previous = getPermissionsTeamId();
        setPermissionsTeamId($team->id);
        try {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->revokePermissionTo(PermissionCatalog::PROGRAMS_SUPERVISE);
        } finally {
            setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
