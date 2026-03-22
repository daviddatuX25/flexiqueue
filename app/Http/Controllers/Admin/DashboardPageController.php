<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per SUPER-ADMIN-VS-ADMIN-SPEC: dashboard route renders different view by role.
 * Super_admin gets platform summary (sites, admins); admin gets current program/station dashboard.
 */
class DashboardPageController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        if ($user->can(PermissionCatalog::PLATFORM_MANAGE)) {
            $sitesCount = Site::query()->count();
            $adminsCount = User::query()->where('role', 'admin')->count();

            return Inertia::render('Admin/DashboardSuperAdmin', [
                'sites_count' => $sitesCount,
                'admins_count' => $adminsCount,
            ]);
        }

        return Inertia::render('Admin/Dashboard');
    }
}
