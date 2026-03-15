<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Services\StationQueueService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public homepage. Per HOMEPAGE-PLAN: Option B — public landing + auth strip.
 * No auth required. Shared props (auth.user, activeProgram, flash) from HandleInertiaRequests.
 */
class HomeController extends Controller
{
    public function __construct(
        private StationQueueService $stationQueueService
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $dashboardRoute = null;
        $dashboardLabel = null;
        $roleBadge = null;
        $queueCount = 0;
        $processedToday = 0;

        if ($user) {
            $roleBadge = $user->role->value;
            if ($user->isAdmin() || $user->isSupervisorForAnyProgram()) {
                $dashboardRoute = route('admin.dashboard');
                $dashboardLabel = 'Go to your dashboard';
            } else {
                $dashboardRoute = route('station');
                $dashboardLabel = 'Go to station';
            }
        }

        $activeProgram = Program::where('is_active', true)->first();
        if ($activeProgram) {
            $stats = $this->stationQueueService->getProgramFooterStats($activeProgram);
            $queueCount = $stats['queue_count'];
            $processedToday = $stats['processed_today'];
        }

        return Inertia::render('Home', [
            'dashboardRoute' => $dashboardRoute,
            'dashboardLabel' => $dashboardLabel,
            'roleBadge' => $roleBadge,
            'appName' => config('app.name'),
            'appEnv' => config('app.env'),
            'appVersion' => config('app.version', '1.0.0-dev'),
            'queueCount' => $queueCount,
            'processedToday' => $processedToday,
            'hasActiveProgram' => $activeProgram !== null,
            // Use Laravel's asset() helper so the hero background image always
            // resolves against the correct app URL (host + port), both in dev and prod.
            'heroImageUrl' => asset('images/mswdo_tagudin.jpg'),
        ]);
    }
}
