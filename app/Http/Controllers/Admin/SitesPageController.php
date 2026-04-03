<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RbacTeam;
use App\Models\Site;
use App\Models\SiteShortLink;
use App\Services\SiteApiKeyService;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per central-edge B.5: Admin UI for site management (list, create, show with masked key and edge settings).
 * Users are managed on the Staff page only; one admin manages one site.
 */
class SitesPageController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $authUser = $request->user();
        // Per plan: site-scoped admin has no Sites nav; redirect if they hit the URL directly.
        if (! $authUser->can(PermissionCatalog::PLATFORM_MANAGE)) {
            return redirect()->route('admin.dashboard');
        }
        $query = Site::query()->orderBy('name');
        $sites = $query->get()->map(fn (Site $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'slug' => $s->slug,
            'is_default' => (bool) ($s->is_default ?? false),
            'created_at' => $s->created_at?->toIso8601String(),
        ]);

        $defaultSiteId = Site::where('is_default', true)->value('id');

        return Inertia::render('Admin/Sites/Index', [
            'sites' => $sites,
            'default_site_id' => $defaultSiteId,
            'auth_is_super_admin' => $authUser->can(PermissionCatalog::PLATFORM_MANAGE),
        ]);
    }

    public function create(Request $request): Response
    {
        if (! $request->user()->can(PermissionCatalog::PLATFORM_MANAGE)) {
            abort(403, 'Only a super admin can create sites.');
        }

        return Inertia::render('Admin/Sites/Create');
    }

    public function show(Request $request, Site $site): Response
    {
        $authUser = $request->user();
        if (! $authUser->can(PermissionCatalog::PLATFORM_MANAGE) && $authUser->site_id !== $site->id) {
            abort(404);
        }

        $defaultSiteId = Site::where('is_default', true)->value('id');

        $settings = $site->settings ?? [];
        $heroPath = $settings['landing_hero_image_path'] ?? null;
        $landingHeroImageUrl = null;
        if (is_string($heroPath) && $heroPath !== '') {
            $landingHeroImageUrl = Storage::disk('public')->url($heroPath);
        }

        $siteEntryLink = SiteShortLink::query()
            ->where('site_id', $site->id)
            ->where('type', SiteShortLink::TYPE_SITE_ENTRY)
            ->first();

        $payload = [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'slug' => $site->slug,
                'is_default' => (bool) ($site->is_default ?? false),
                'settings' => $settings,
                'edge_settings' => $site->edge_settings ?? [],
                'created_at' => $site->created_at?->toIso8601String(),
                'updated_at' => $site->updated_at?->toIso8601String(),
                'landing_hero_image_url' => $landingHeroImageUrl,
            ],
            'landing' => [
                'hero_title' => $settings['landing_hero_title'] ?? $site->name,
                'hero_description' => $settings['landing_hero_description'] ?? null,
                'hero_image_url' => $landingHeroImageUrl,
                'sections' => $settings['landing_sections'] ?? [],
                'show_stats' => (bool) ($settings['landing_show_stats'] ?? false),
                'public_access_key' => $settings['public_access_key'] ?? null,
            ],
            'site_entry_short_url' => $siteEntryLink
                ? rtrim(config('app.url'), '/').'/go/'.$siteEntryLink->code
                : null,
            'site_landing_url' => url('/site/'.$site->slug),
            'api_key_masked' => SiteApiKeyService::maskedPlaceholder(),
            'default_site_id' => $defaultSiteId,
            'auth_is_super_admin' => $authUser->can(PermissionCatalog::PLATFORM_MANAGE),
            'rbac_team' => [
                'id' => RbacTeam::forSite($site)->id,
                'type' => 'site',
                'site_id' => $site->id,
                'scope_label' => $site->name,
            ],
        ];

        if ($authUser->can(PermissionCatalog::PLATFORM_MANAGE)) {
            $payload['sites'] = Site::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'is_default'])
                ->map(fn (Site $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'slug' => $s->slug,
                    'is_default' => (bool) ($s->is_default ?? false),
                ])
                ->values()
                ->all();
        }

        $payload['programs'] = \App\Models\Program::where('site_id', $site->id)
            ->select(['id', 'name', 'edge_locked_by_device_id'])
            ->orderBy('name')
            ->get()
            ->map(fn (\App\Models\Program $p) => [
                'id'                       => $p->id,
                'name'                     => $p->name,
                'edge_locked_by_device_id' => $p->edge_locked_by_device_id,
            ])
            ->values()
            ->all();

        return Inertia::render('Admin/Sites/Show', $payload);
    }
}
