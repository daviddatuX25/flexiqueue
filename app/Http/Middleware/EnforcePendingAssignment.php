<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per HYBRID_AUTH_ADMIN_FIRST_PRD.md ONB-5 / H5: staff with pending_assignment may only reach
 * the holding page, profile (identity/recovery), and logout until an admin assigns a station or clears the flag.
 */
class EnforcePendingAssignment
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (! $user->isStaff() || ! $user->pending_assignment) {
            return $next($request);
        }

        if ($this->isExempt($request)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Your account is pending assignment by an administrator.',
            ], 403);
        }

        return redirect()->route('pending-assignment');
    }

    private function isExempt(Request $request): bool
    {
        if ($request->routeIs('pending-assignment')) {
            return true;
        }

        if ($request->is('pending-assignment')) {
            return true;
        }

        if ($request->is('logout') && $request->isMethod('post')) {
            return true;
        }

        if ($request->routeIs('profile')) {
            return true;
        }

        if ($request->is('profile')) {
            return true;
        }

        if ($request->is('api/profile') || $request->is('api/profile/*')) {
            return true;
        }

        if ($request->is('api/users/me') || $request->is('api/users/me/*')) {
            return true;
        }

        // Google OAuth: profile "Link Google" + return from OAuth while pending staff.
        if ($request->routeIs('auth.google.link') || $request->routeIs('auth.google.callback')) {
            return true;
        }

        if ($request->is('auth/google/link') || $request->is('auth/google/callback')) {
            return true;
        }

        return false;
    }
}
