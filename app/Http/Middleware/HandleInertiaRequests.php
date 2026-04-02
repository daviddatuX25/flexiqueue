<?php

namespace App\Http\Middleware;

use App\Http\Controllers\StationPageController;
use App\Models\Program;
use App\Models\Station;
use App\Models\TtsPlatformBudget;
use App\Repositories\TokenTtsSettingRepository;
use App\Services\EdgeModeService;
use App\Services\TtsService;
use App\Support\DeviceLock;
use App\Support\PermissionCatalog;
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

        // Load roles and permissions relationships to ensure user.role attribute works when serialized to JSON
        // Fix for: primaryGlobalRoleName() was returning null for admin when roles weren't eager-loaded
        if ($user) {
            $user->load('roles', 'permissions');
        }

        $canApproveRequests = $user && (
            $user->can(PermissionCatalog::ADMIN_MANAGE)
            || $user->can(PermissionCatalog::ADMIN_SHARED)
            || $user->can(PermissionCatalog::AUTH_SUPERVISOR_TOOLS)
        );

        $base = [
            ...parent::share($request),
            'staff_triage_page_enabled' => config('flexiqueue.staff_triage_page_enabled', true),
            'csrf_token' => csrf_token(),
            'flash' => [
                'status' => $request->session()->get('status'),
                'error' => $request->session()->get('error'),
                'success' => $request->session()->get('success'),
            ],
            'auth' => [
                'user' => $user,
                /** Single source of truth for Configuration / super-admin UI (see super_admin_settings_nav plan). */
                'is_super_admin' => $user?->isSuperAdmin() ?? false,
                /** @deprecated Prefer auth.can.approve_requests (Phase 5 RBAC). */
                'can_approve_requests' => $canApproveRequests,
                /** Stable capability flags from $user->can(); see docs/architecture/PERMISSIONS-MATRIX.md Phase 5. */
                'can' => [
                    'public_device_authorize' => $user?->can(PermissionCatalog::PUBLIC_DEVICE_AUTHORIZE) ?? false,
                    'public_display_settings_apply' => $user?->can(PermissionCatalog::PUBLIC_DISPLAY_SETTINGS_APPLY) ?? false,
                    'approve_requests' => $canApproveRequests,
                    'staff_operations' => $user?->can(PermissionCatalog::STAFF_OPERATIONS) ?? false,
                    'admin_manage' => $user?->can(PermissionCatalog::ADMIN_MANAGE) ?? false,
                ],
            ],
            'server_tts_configured' => $user?->can(PermissionCatalog::ADMIN_MANAGE)
                ? app(TtsService::class)->isEnabled()
                : null,
            // Lockout applies only to non-staff/admin; staff/admin can exit without PIN/QR.
            'device_locked' => self::deviceLockedForRequest($request),
            'device_locked_redirect_url' => self::deviceLockedRedirectUrl($request),
            'edge_mode' => [
                'is_edge' => app(EdgeModeService::class)->isEdge(),
                'is_online' => app(EdgeModeService::class)->isOnline(),
                'is_offline' => app(EdgeModeService::class)->isOffline(),
                'admin_read_only' => app(EdgeModeService::class)->isAdminReadOnly(),
                'sync_back' => app(EdgeModeService::class)->syncBack(),
                'bridge_mode_enabled' => app(EdgeModeService::class)->bridgeModeEnabled(),
            ],
        ];

        // Per central-edge A.2.5 / A.4.1: admin routes receive programs (all active); currentProgram only (A.4.4: program alias removed).
        // Per central-edge B.4: admin programs list is site-scoped; empty if user has no site_id.
        // Share first active non-paused program as currentProgram so StatusFooter chip is clickable when any program is active (not only on Programs page).
        if ($request->routeIs('admin.*')) {
            try {
                $siteId = $user?->site_id;
                $base['programs'] = Program::query()
                    ->forSite($siteId)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->toArray();
                $firstActive = Program::query()
                    ->forSite($siteId)
                    ->where('is_active', true)
                    ->where(fn ($q) => $q->where('is_paused', false)->orWhereNull('is_paused'))
                    ->orderBy('name')
                    ->first(['id', 'name', 'is_active', 'is_paused']);
                $base['currentProgram'] = $firstActive
                    ? ['id' => $firstActive->id, 'name' => $firstActive->name, 'is_active' => (bool) $firstActive->is_active, 'is_paused' => (bool) $firstActive->is_paused]
                    : null;
            } catch (\Throwable) {
                $base['programs'] = [];
                $base['currentProgram'] = null;
            }

            try {
                $playback = app(TokenTtsSettingRepository::class)->getInstance()->getPlayback();
                $base['tts_allow_custom_pronunciation'] = $playback['allow_custom_pronunciation'];
                $base['tts_segment_2_enabled'] = $playback['segment_2_enabled'];
            } catch (\Throwable) {
                $base['tts_allow_custom_pronunciation'] = true;
                $base['tts_segment_2_enabled'] = true;
            }

            try {
                $base['tts_global_budget_enabled'] = (bool) TtsPlatformBudget::settings()->global_enabled;
            } catch (\Throwable) {
                $base['tts_global_budget_enabled'] = false;
            }

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

    /**
     * When device is locked, return the URL to redirect to (for client-side back/cache guard). Null when not locked.
     */
    private static function deviceLockedRedirectUrl(Request $request): ?string
    {
        $lock = DeviceLock::decode($request);

        return $lock !== null ? DeviceLock::redirectUrlForLock($lock) : null;
    }

    /**
     * Device lockout for guests and users without public.device.authorize (Spatie).
     */
    private static function deviceLockedForRequest(Request $request): bool
    {
        $user = $request->user();
        if ($user && $user->can(PermissionCatalog::PUBLIC_DEVICE_AUTHORIZE)) {
            return false;
        }

        return DeviceLock::isLocked($request);
    }
}
