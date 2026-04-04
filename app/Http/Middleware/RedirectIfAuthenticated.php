<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\PermissionCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per 05-SECURITY-CONTROLS: redirect authenticated users from login to role dashboard.
 */
class RedirectIfAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User) {
            if ($user->can(PermissionCatalog::ADMIN_SHARED)) {
                return redirect()->route('admin.dashboard');
            }

            if ($user->isStaff() && $user->pending_assignment) {
                return redirect()->route('pending-assignment');
            }

            return redirect()->route('station');
        }

        return $next($request);
    }
}
