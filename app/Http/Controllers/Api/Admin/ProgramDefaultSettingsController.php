<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProgramDefaultSettingsRequest;
use App\Repositories\ProgramDefaultSettingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Site-scoped default program settings (program_default_settings.site_id = auth user's site).
 * Per docs/plans/PER_SITE_PROGRAM_DEFAULTS_AND_PLATFORM_TEMPLATE.md: role:admin with site_id only.
 */
class ProgramDefaultSettingsController extends Controller
{
    public function __construct(
        private ProgramDefaultSettingRepository $programDefaultSettingRepository
    ) {}

    /**
     * GET /api/admin/program-default-settings — Defaults for the authenticated admin's site.
     */
    public function show(Request $request): JsonResponse
    {
        $siteId = $this->requireSiteId($request);

        return response()->json([
            'settings' => $this->programDefaultSettingRepository->getNormalizedForSite($siteId),
        ]);
    }

    /**
     * PUT /api/admin/program-default-settings — Save site-scoped defaults.
     */
    public function update(UpdateProgramDefaultSettingsRequest $request): JsonResponse
    {
        $siteId = $this->requireSiteId($request);
        $settings = $request->validated()['settings'];
        $normalized = $this->programDefaultSettingRepository->normalizeSettings($settings);
        $this->programDefaultSettingRepository->persistForSite($siteId, $normalized);

        return response()->json(['settings' => $normalized]);
    }

    private function requireSiteId(Request $request): int
    {
        $user = $request->user();
        if ($user === null || $user->site_id === null) {
            abort(403, 'You must be assigned to a site to manage program default settings.');
        }

        return (int) $user->site_id;
    }
}
