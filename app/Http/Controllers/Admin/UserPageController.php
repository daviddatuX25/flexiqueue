<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Site;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin Users page with staff assignment. Per 09-UI-ROUTES-PHASE1, 08-API-SPEC §5.7.
 * Per assign-site-to-user: super_admin sees all users and gets sites list for dropdown.
 */
class UserPageController extends Controller
{
    public function index(Request $request): Response
    {
        $authUser = $request->user();
        $siteId = $authUser->site_id;

        $query = User::query()
            ->with(['assignedStation', 'site', 'roles', 'permissions'])
            ->withCount('supervisedPrograms')
            ->orderBy('name');
        if ($authUser->can(PermissionCatalog::PLATFORM_MANAGE)) {
            $filterSiteId = $request->query('site_id');
            if (is_numeric($filterSiteId)) {
                $query->forSite((int) $filterSiteId);
            } else {
                $query->where('role', UserRole::Admin->value);
            }
        } else {
            $query->forSite($siteId);
        }

        $users = $query->get()->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'avatar_url' => $u->avatar_url,
            'role' => $u->role->value,
            'is_active' => $u->is_active,
            'availability_status' => $u->availability_status ?? 'offline',
            'assigned_station_id' => $u->assigned_station_id,
            'assigned_station' => $u->assignedStation ? [
                'id' => $u->assignedStation->id,
                'name' => $u->assignedStation->name,
            ] : null,
            'site' => $u->site ? ['id' => $u->site->id, 'name' => $u->site->name, 'slug' => $u->site->slug] : null,
            'spatie_roles' => $u->roles->pluck('name')->values()->all(),
            'direct_permissions' => $u->getDirectPermissions()->pluck('name')->values()->all(),
            'effective_permissions' => $u->getAllPermissions()->pluck('name')->values()->all(),
            'supervisor_program_count' => (int) ($u->supervised_programs_count ?? 0),
        ]);

        // Per central-edge Phase A: program from query. B.4: site admin only sees programs in their site; super_admin may pick any.
        $programId = $request->query('program');
        $program = null;
        if (is_numeric($programId)) {
            $program = $authUser->can(PermissionCatalog::PLATFORM_MANAGE)
                ? Program::query()->find((int) $programId)
                : Program::query()->forSite($siteId)->find((int) $programId);
        }
        $stations = [];
        if ($program && $program->is_active) {
            $stations = $program->stations()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])
                ->values()
                ->all();
        }

        $payload = [
            'users' => $users,
            'stations' => $stations,
            'auth_is_super_admin' => $authUser->can(PermissionCatalog::PLATFORM_MANAGE),
            'auth_user_id' => $authUser->id,
            'allowed_roles_for_create' => $authUser->can(PermissionCatalog::PLATFORM_MANAGE) ? ['admin'] : ['staff', 'admin'],
            'allowed_roles_for_edit' => $authUser->can(PermissionCatalog::PLATFORM_MANAGE) ? ['admin'] : ['staff', 'admin'],
            'assignable_permissions' => PermissionCatalog::assignableDirect(),
        ];
        if ($authUser->can(PermissionCatalog::PLATFORM_MANAGE)) {
            $payload['sites'] = Site::query()->orderBy('name')->get(['id', 'name', 'slug'])->map(fn (Site $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
            ])->values()->all();
        }

        return Inertia::render('Admin/Users/Index', $payload);
    }
}
