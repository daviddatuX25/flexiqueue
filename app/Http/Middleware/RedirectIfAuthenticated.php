<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
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
        if ($request->user()) {
            $user = $request->user();

            if ($user->role === UserRole::Admin || $user->role === UserRole::SuperAdmin) {
                return redirect()->route('admin.dashboard');
            }

            return redirect()->route('station');
        }

        return $next($request);
    }
}
