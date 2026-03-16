<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per 05-SECURITY-CONTROLS §3.4: RBAC — allow only given roles. Returns 403 if unauthorized.
 * Usage: ->middleware('role:admin') or ->middleware('role:admin,supervisor')
 */
class EnsureRole
{
    /**
     * @param  string  ...$roles  Allowed role values (e.g. 'admin', 'supervisor', 'staff')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Unauthenticated.'], 401)
                : redirect()->guest(route('login'));
        }

        $roleValue = $user->role instanceof UserRole
            ? $user->role->value
            : (string) $user->role;

        $allowed = in_array($roleValue, $roles, true);

        if (! $allowed && in_array('supervisor', $roles, true)) {
            $allowed = $user->isAdmin() || ($user->role === UserRole::Staff && $user->isSupervisorForAnyProgram());
        }

        if (! $allowed) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Forbidden.'], 403)
                : abort(403, 'Forbidden.');
        }

        return $next($request);
    }
}
