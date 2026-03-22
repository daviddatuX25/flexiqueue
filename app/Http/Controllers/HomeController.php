<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\HomeStatsService;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public homepage. Per public-site plan: no queue stats from StationQueueService;
 * global stats come from GET /api/home-stats. default_site_slug for optional redirect.
 */
class HomeController extends Controller
{
    public function __construct(
        private HomeStatsService $homeStatsService
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $dashboardRoute = null;
        $dashboardLabel = null;
        $roleBadge = null;

        if ($user) {
            $roleBadge = $user->role->value;
            if ($user->can(PermissionCatalog::PLATFORM_MANAGE)) {
                $dashboardRoute = route('admin.dashboard');
                $dashboardLabel = 'Go to admin console';
            } elseif ($user->can(PermissionCatalog::ADMIN_MANAGE)
                || ($user->can(PermissionCatalog::PROGRAMS_SUPERVISE) && $user->isSupervisorForAnyProgram())) {
                $dashboardRoute = route('admin.dashboard');
                $dashboardLabel = 'Go to your dashboard';
            } else {
                $dashboardRoute = route('station');
                $dashboardLabel = 'Go to station';
            }
        }

        $defaultSiteSlug = Site::query()->where('is_default', true)->value('slug');
        $homeStats = ['served_count' => 0, 'session_hours' => 0.0];

        try {
            $homeStats = $this->homeStatsService->getGlobalStats();
        } catch (\Throwable) {
            // Keep homepage resilient even if stats query is temporarily unavailable.
        }

        return Inertia::render('Home', [
            'dashboardRoute' => $dashboardRoute,
            'dashboardLabel' => $dashboardLabel,
            'roleBadge' => $roleBadge,
            'appName' => config('app.name'),
            'appEnv' => config('app.env'),
            'appVersion' => config('app.version', '1.0.0-dev'),
            'default_site_slug' => $defaultSiteSlug,
            'heroImageUrl' => asset('images/mswdo_tagudin.jpg'),
            'homeStats' => $homeStats,
        ]);
    }
}
