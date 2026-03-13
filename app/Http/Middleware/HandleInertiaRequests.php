<?php

namespace App\Http\Middleware;

use App\Http\Controllers\StationPageController;
use App\Models\Program;
use App\Models\Station;
use App\Services\TtsService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * Per central-edge A.4.1 / A.4.4: shared currentProgram (nullable) only.
     * Admin routes: programs array only; currentProgram is null.
     * Non-admin (station, triage, display): currentProgram resolved per request context.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        $base = [
            ...parent::share($request),
            'csrf_token' => csrf_token(),
            'flash' => [
                'status' => $request->session()->get('status'),
                'error' => $request->session()->get('error'),
                'success' => $request->session()->get('success'),
            ],
            'auth' => [
                'user' => $user,
            ],
            'server_tts_configured' => $user?->role === 'admin'
                ? app(TtsService::class)->isEnabled()
                : null,
        ];

        // Per central-edge A.2.5 / A.4.1: admin routes receive programs (all active); currentProgram only (A.4.4: program alias removed).
        // Per central-edge B.4: admin programs list is site-scoped; empty if user has no site_id.
        if ($request->routeIs('admin.*')) {
            try {
                $siteId = $user?->site_id;
                $base['programs'] = Program::query()
                    ->forSite($siteId)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->toArray();
            } catch (\Throwable) {
                $base['programs'] = [];
            }
            $base['currentProgram'] = null;

            return $base;
        }

        // Per A.4.1: non-admin routes get currentProgram resolved per spec.
        $currentProgram = $this->resolveCurrentProgramForSharedData($request);
        $base['currentProgram'] = $currentProgram;

        return $base;
    }

    /**
     * Resolve current program for shared Inertia data per central-edge spec.
     * Station: from route station or user's assigned_station → program.
     * Triage: from user's assigned_station → program (or session for admin/supervisor without station).
     * Display: from query param ?program= (active program id).
     * display.station: from route station → program.
     *
     * @return array{id: int, name: string}|null
     */
    private function resolveCurrentProgramForSharedData(Request $request): ?array
    {
        if ($request->routeIs('station')) {
            $station = $request->route('station');
            if ($station instanceof Station) {
                $program = $station->program;
                if ($program) {
                    return ['id' => $program->id, 'name' => $program->name];
                }
            }
            $user = $request->user();
            $program = $user?->assignedStation?->program;
            if ($program) {
                return ['id' => $program->id, 'name' => $program->name];
            }
            if ($user && ($user->isAdmin() || $user->isSupervisorForAnyProgram())) {
                $program = $this->resolveProgramFromSessionOrFirstActive($request);
                if ($program) {
                    return ['id' => $program->id, 'name' => $program->name];
                }
            }

            return null;
        }

        if ($request->routeIs('triage')) {
            $user = $request->user();
            $program = $user?->assignedStation?->program;
            if ($program) {
                return ['id' => $program->id, 'name' => $program->name];
            }
            if ($user && ($user->isAdmin() || $user->isSupervisorForAnyProgram())) {
                $program = $this->resolveProgramFromSessionOrFirstActive($request);
                if ($program) {
                    return ['id' => $program->id, 'name' => $program->name];
                }
            }

            return null;
        }

        if ($request->routeIs('display')) {
            $programId = $request->query('program');
            $programId = is_numeric($programId) ? (int) $programId : null;
            if ($programId === null) {
                return null;
            }
            try {
                $program = Program::query()
                    ->where('id', $programId)
                    ->where('is_active', true)
                    ->first(['id', 'name']);
                if ($program) {
                    return ['id' => $program->id, 'name' => $program->name];
                }
            } catch (\Throwable) {
                // ignore
            }

            return null;
        }

        if ($request->routeIs('display.station')) {
            $station = $request->route('station');
            if ($station instanceof Station) {
                $program = $station->program;
                if ($program) {
                    return ['id' => $program->id, 'name' => $program->name];
                }
            }

            return null;
        }

        return null;
    }

    /**
     * Resolve program for admin/supervisor without assigned station: session then first active.
     */
    private function resolveProgramFromSessionOrFirstActive(Request $request): ?Program
    {
        $sessionId = $request->session()->get(StationPageController::SESSION_KEY_PROGRAM_ID);
        if ($sessionId) {
            $program = Program::query()->where('id', (int) $sessionId)->where('is_active', true)->first();
            if ($program) {
                return $program;
            }
        }

        try {
            return Program::query()->where('is_active', true)->orderBy('name')->first();
        } catch (\Throwable) {
            return null;
        }
    }
}
