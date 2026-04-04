<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Station;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin Audit log page (audit log viewer + export). Per 09-UI-ROUTES-PHASE1 §3.11.
 */
class ReportPageController extends Controller
{
    public function index(Request $request): Response
    {
        $siteId = $request->user()?->site_id;
        $programs = Program::query()
            ->forSite($siteId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])
            ->values()
            ->all();

        $programIds = collect($programs)->pluck('id')->all();
        $stations = Station::query()
            ->whereIn('program_id', $programIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'program_id'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'program_id' => $s->program_id])
            ->values()
            ->all();

        $staffUsers = User::query()
            ->forSite($siteId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();

        $authUser = $request->user();

        return Inertia::render('Admin/Logs/Index', [
            'programs' => $programs,
            'stations' => $stations,
            'staffUsers' => $staffUsers,
            'auth_is_super_admin' => $authUser->can(PermissionCatalog::PLATFORM_MANAGE),
        ]);
    }
}
