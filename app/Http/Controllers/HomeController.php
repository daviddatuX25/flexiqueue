<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public homepage. Per public-site plan: no queue stats from StationQueueService;
 * global stats come from GET /api/home-stats. default_site_slug for optional redirect.
 */
class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $dashboardRoute = null;
        $dashboardLabel = null;
        $roleBadge = null;

        if ($user) {
            $roleBadge = $user->role->value;
            if ($user->isSuperAdmin()) {
                $dashboardRoute = route('admin.dashboard');
                $dashboardLabel = 'Go to admin console';
            } elseif ($user->isAdmin() || $user->isSupervisorForAnyProgram()) {
                $dashboardRoute = route('admin.dashboard');
                $dashboardLabel = 'Go to your dashboard';
            } else {
                $dashboardRoute = route('station');
                $dashboardLabel = 'Go to station';
            }
        }

        $defaultSiteSlug = Site::query()->where('is_default', true)->value('slug');

        return Inertia::render('Home', [
            'dashboardRoute' => $dashboardRoute,
            'dashboardLabel' => $dashboardLabel,
            'roleBadge' => $roleBadge,
            'appName' => config('app.name'),
            'appEnv' => config('app.env'),
            'appVersion' => config('app.version', '1.0.0-dev'),
            'default_site_slug' => $defaultSiteSlug,
            'heroImageUrl' => asset('images/mswdo_tagudin.jpg'),
        ]);
    }
}
