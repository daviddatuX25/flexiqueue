<?php

namespace App\Http\Middleware;

use App\Models\RbacTeam;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spatie teams: default HTTP context uses global team (pivot/role rows aligned with RbacTeam::GLOBAL_TEAM_ID).
 *
 * @see docs/architecture/PERMISSIONS-TEAMS-AND-UI.md
 */
class SetGlobalPermissionsTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        setPermissionsTeamId(RbacTeam::GLOBAL_TEAM_ID);

        return $next($request);
    }
}
