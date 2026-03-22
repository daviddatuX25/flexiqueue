<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateRbacTeamUserPermissionsRequest;
use App\Models\RbacTeam;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 6: direct permissions scoped to a site or program RbacTeam (Spatie team_id).
 */
class RbacTeamUserPermissionController extends Controller
{
    public function show(Request $request, RbacTeam $rbacTeam, User $user): JsonResponse
    {
        if ($rbacTeam->id === RbacTeam::GLOBAL_TEAM_ID) {
            throw ValidationException::withMessages([
                'rbac_team' => ['Use GET /api/admin/users and global user edit for team_id '.RbacTeam::GLOBAL_TEAM_ID.'.'],
            ]);
        }

        $this->authorizeTeamAndUser($request, $rbacTeam, $user);

        $previous = getPermissionsTeamId();
        setPermissionsTeamId($rbacTeam->id);
        $scopedNames = [];
        try {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $scopedNames = $user->getDirectPermissions()->pluck('name')->values()->all();
        } finally {
            setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }

        return response()->json([
            'rbac_team_id' => $rbacTeam->id,
            'user_id' => $user->id,
            'direct_permissions' => $scopedNames,
        ]);
    }

    public function update(UpdateRbacTeamUserPermissionsRequest $request, RbacTeam $rbacTeam, User $user): JsonResponse
    {
        if ($rbacTeam->id === RbacTeam::GLOBAL_TEAM_ID) {
            throw ValidationException::withMessages([
                'rbac_team' => ['Use PUT /api/admin/users/{user} with direct_permissions for global scope.'],
            ]);
        }

        $this->authorizeTeamAndUser($request, $rbacTeam, $user);

        $names = $request->validated('direct_permissions');
        $this->assertCanAssignDirectPermissions($request->user(), $names);

        $previous = getPermissionsTeamId();
        setPermissionsTeamId($rbacTeam->id);
        $scopedNames = [];
        try {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->syncPermissions($names);
            $scopedNames = $user->getDirectPermissions()->pluck('name')->values()->all();
        } finally {
            setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }

        return response()->json([
            'rbac_team_id' => $rbacTeam->id,
            'user_id' => $user->id,
            'direct_permissions' => $scopedNames,
        ]);
    }

    private function authorizeTeamAndUser(Request $request, RbacTeam $rbacTeam, User $user): void
    {
        $auth = $request->user();
        if ($auth->isSuperAdmin()) {
            return;
        }

        if (! $auth->isAdmin()) {
            abort(403);
        }

        if ($rbacTeam->type === 'site') {
            if ($auth->site_id !== $rbacTeam->site_id) {
                abort(403);
            }
            if ($user->site_id !== $rbacTeam->site_id) {
                abort(404);
            }

            return;
        }

        if ($rbacTeam->type === 'program') {
            $program = $rbacTeam->program;
            if (! $program || $auth->site_id !== $program->site_id) {
                abort(403);
            }
            if ($user->site_id !== $program->site_id) {
                abort(404);
            }

            return;
        }

        abort(403);
    }

    /**
     * @param  list<string>  $names
     */
    private function assertCanAssignDirectPermissions(User $auth, array $names): void
    {
        if (in_array(PermissionCatalog::PLATFORM_MANAGE, $names, true) && ! $auth->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'direct_permissions' => ['Only a super admin may assign platform.manage.'],
            ]);
        }
    }
}
