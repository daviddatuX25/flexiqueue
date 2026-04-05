<?php

namespace App\Http\Middleware;

use App\Models\EdgeDeviceState;
use App\Services\EdgeModeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per CI-CD-and-Edge-plan B7: on edge mode, redirect to /edge/setup or /edge/waiting
 * when not yet paired or when no program is assigned.
 */
class EdgeBootGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(EdgeModeService::class)->isEdge()) {
            return $next($request);
        }

        // API routes return JSON (401/403/409/…) — do not redirect to browser setup/waiting screens.
        if ($request->is('api/*')) {
            return $next($request);
        }

        $state = EdgeDeviceState::current();

        // E9.4: revoked devices get their own terminal page
        if ($state->is_revoked) {
            if (! $request->is('edge/revoked')) {
                return redirect('/edge/revoked');
            }
            return $next($request);
        }

        if ($state->paired_at === null) {
            if (! $request->is('edge/setup') && ! $request->is('edge/setup*')) {
                return redirect('/edge/setup');
            }

            return $next($request);
        }

        if ($state->active_program_id === null) {
            if (! $request->is('edge/waiting') && ! $request->is('edge/waiting*') && ! $request->is('edge/setup') && ! $request->is('edge/setup*')) {
                return redirect('/edge/waiting');
            }

            return $next($request);
        }

        return $next($request);
    }
}
