<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSiteRequest;
use App\Http\Requests\UpdateSiteRequest;
use App\Models\AdminActionLog;
use App\Models\Site;
use App\Models\TtsPlatformBudget;
use App\Repositories\PrintSettingRepository;
use App\Repositories\ProgramDefaultSettingRepository;
use App\Services\EdgeModeService;
use App\Services\SiteApiKeyService;
use App\Support\SiteResolver;
use App\Validation\EdgeSettingsValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Per central-edge-v2-final §Phase B: site CRUD and API key lifecycle.
 * RBAC: only super_admin may create sites. Site admin may only list/view/update their assigned site.
 */
class SiteController extends Controller
{
    public function __construct(
        private SiteApiKeyService $siteApiKeyService,
        private EdgeSettingsValidator $edgeSettingsValidator,
        private PrintSettingRepository $printSettingRepository,
        private ProgramDefaultSettingRepository $programDefaultSettingRepository
    ) {}

    /**
     * List sites. Super admin sees all; site admin sees only their assigned site.
     */
    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();
        $query = Site::query()->orderBy('name');
        if (! $authUser->isSuperAdmin()) {
            if ($authUser->site_id === null) {
                return response()->json(['sites' => []]);
            }
            $query->where('id', $authUser->site_id);
        }
        $sites = $query->get()->map(fn (Site $s) => $this->siteResource($s));

        return response()->json(['sites' => $sites]);
    }

    /**
     * Create site: only super_admin. Generate key, store hash, return raw key once.
     */
    public function store(StoreSiteRequest $request): JsonResponse
    {
        if (! $request->user()->isSuperAdmin()) {
            abort(403, 'Only a super admin can create sites.');
        }
        $validated = $request->validated();
        $site = new Site;
        $site->name = $validated['name'];
        $site->slug = $validated['slug'];
        $site->settings = $validated['settings'] ?? [];
        $edgeSettings = $validated['edge_settings'] ?? [];
        $site->edge_settings = ! empty($edgeSettings)
            ? $this->edgeSettingsValidator->validate($edgeSettings)
            : $this->edgeSettingsValidator->validate([]);
        $site->api_key_hash = Hash::make(Str::random(32)); // temporary; assignNewKey overwrites
        $site->save();

        // Platform print template (super_admin, site_id null) is copied into the new site — same source as Print platform defaults UI.
        $this->printSettingRepository->copyPlatformTemplateToSite($site->id);
        // Platform program defaults (site_id null) seed this site's program-default row — same pattern as print.
        $this->programDefaultSettingRepository->copyPlatformTemplateToSite($site->id);

        $rawKey = $this->siteApiKeyService->assignNewKey($site);

        AdminActionLog::log($request->user()->id, 'site_created', 'Site', $site->id, ['slug' => $site->slug]);

        return response()->json([
            'site' => $this->siteResource($site),
            'api_key' => $rawKey,
        ], 201);
    }

    /**
     * Show site; never return raw key — only masked placeholder. Site admin may only view their assigned site.
     */
    public function show(Request $request, Site $site): JsonResponse
    {
        $this->ensureSiteAccess($request, $site);

        return response()->json([
            'site' => $this->siteResource($site),
            'api_key_masked' => SiteApiKeyService::maskedPlaceholder(),
        ]);
    }

    /**
     * Regenerate API key: new key, replace hash, return new raw key once; old key invalid immediately.
     */
    public function regenerateKey(Request $request, Site $site): JsonResponse
    {
        $this->ensureSiteAccess($request, $site);

        $rawKey = $this->siteApiKeyService->assignNewKey($site);

        AdminActionLog::log($request->user()->id, 'site_regenerate_key', 'Site', $site->id, ['slug' => $site->slug]);

        return response()->json([
            'site' => $this->siteResource($site),
            'api_key' => $rawKey,
        ]);
    }

    /**
     * Update site (name, slug, edge_settings). Per B.5: edge_settings validated via EdgeSettingsValidator. Site admin may only update their assigned site.
     */
    public function update(UpdateSiteRequest $request, Site $site): JsonResponse
    {
        $this->ensureSiteAccess($request, $site);
        $validated = $request->validated();

        if (array_key_exists('name', $validated)) {
            $site->name = $validated['name'];
        }
        if (array_key_exists('slug', $validated)) {
            $site->slug = $validated['slug'];
        }
        // Per design: edge does not sync back; Edge settings are configured on central only (SYNC_BACK=true).
        if (array_key_exists('edge_settings', $validated) && app(EdgeModeService::class)->syncBack()) {
            $site->edge_settings = $this->edgeSettingsValidator->validate($validated['edge_settings']);
        }
        if (array_key_exists('settings', $validated) && is_array($validated['settings'])) {
            $allowed = ['public_access_key', 'landing_hero_title', 'landing_hero_description', 'landing_sections', 'landing_show_stats', 'tts_budget'];
            $current = $site->settings ?? [];
            foreach ($allowed as $key) {
                if (! array_key_exists($key, $validated['settings'])) {
                    continue;
                }
                if ($key === 'tts_budget') {
                    // Per TTS platform governance: per-site policy is not editable while global budgeting is on.
                    if (TtsPlatformBudget::settings()->global_enabled) {
                        continue;
                    }
                    if (is_array($validated['settings'][$key])) {
                        $current[$key] = array_merge($current[$key] ?? [], $validated['settings'][$key]);
                    } else {
                        $current[$key] = $validated['settings'][$key];
                    }
                } else {
                    $current[$key] = $validated['settings'][$key];
                }
            }
            $site->settings = $current;
        }
        $site->save();

        AdminActionLog::log($request->user()->id, 'site_updated', 'Site', $site->id, ['slug' => $site->slug]);

        return response()->json(['site' => $this->siteResource($site)]);
    }

    /**
     * Set this site as the default for the deployment (public display/triage). Super_admin only.
     */
    public function setDefault(Request $request, Site $site): JsonResponse
    {
        if (! $request->user()->isSuperAdmin()) {
            abort(403, 'Only a super admin can set the default site.');
        }

        Site::query()->update(['is_default' => false]);
        $site->update(['is_default' => true]);
        SiteResolver::clearDefaultCache();

        AdminActionLog::log($request->user()->id, 'site_set_default', 'Site', $site->id, ['slug' => $site->slug]);

        return response()->json(['site' => $this->siteResource($site)]);
    }

    /**
     * Site admin may only access their assigned site. Super admin may access any site.
     */
    private function ensureSiteAccess(Request $request, Site $site): void
    {
        if ($request->user()->isSuperAdmin()) {
            return;
        }
        if ($request->user()->site_id !== $site->id) {
            abort(404);
        }
    }

    private function siteResource(Site $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'slug' => $site->slug,
            'is_default' => (bool) ($site->is_default ?? false),
            'settings' => $site->settings ?? [],
            'edge_settings' => $site->edge_settings ?? [],
            'created_at' => $site->created_at?->toIso8601String(),
            'updated_at' => $site->updated_at?->toIso8601String(),
        ];
    }
}
