<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use App\Services\SiteApiKeyService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per central-edge B.5: Admin UI for site management (list, create, show with masked key and edge settings).
 * Per assign-site-to-user: show users in this site; super_admin can move user to another site.
 */
class SitesPageController extends Controller
{
    public function index(Request $request): Response
    {
        $authUser = $request->user();
        $query = Site::query()->orderBy('name');
        if (! $authUser->isSuperAdmin()) {
            if ($authUser->site_id === null) {
                return Inertia::render('Admin/Sites/Index', ['sites' => []]);
            }
            $query->where('id', $authUser->site_id);
        }
        $sites = $query->get()->map(fn (Site $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'slug' => $s->slug,
            'created_at' => $s->created_at?->toIso8601String(),
        ]);

        return Inertia::render('Admin/Sites/Index', [
            'sites' => $sites,
            'auth_is_super_admin' => $authUser->isSuperAdmin(),
        ]);
    }

    public function create(Request $request): Response
    {
        if (! $request->user()->isSuperAdmin()) {
            abort(403, 'Only a super admin can create sites.');
        }

        return Inertia::render('Admin/Sites/Create');
    }

    public function show(Request $request, Site $site): Response
    {
        $authUser = $request->user();
        if (! $authUser->isSuperAdmin() && $authUser->site_id !== $site->id) {
            abort(404);
        }
        $usersInSite = User::query()
            ->forSite($site->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role->value,
            ])
            ->values()
            ->all();

        $payload = [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'slug' => $site->slug,
                'settings' => $site->settings ?? [],
                'edge_settings' => $site->edge_settings ?? [],
                'created_at' => $site->created_at?->toIso8601String(),
                'updated_at' => $site->updated_at?->toIso8601String(),
            ],
            'api_key_masked' => SiteApiKeyService::maskedPlaceholder(),
            'users_in_site' => $usersInSite,
            'auth_is_super_admin' => $authUser->isSuperAdmin(),
        ];

        if ($authUser->isSuperAdmin()) {
            $payload['sites'] = Site::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
                ->map(fn (Site $s) => ['id' => $s->id, 'name' => $s->name, 'slug' => $s->slug])
                ->values()
                ->all();
        }

        return Inertia::render('Admin/Sites/Show', $payload);
    }
}
