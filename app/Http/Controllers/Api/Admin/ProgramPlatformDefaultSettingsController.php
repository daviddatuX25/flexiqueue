<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProgramDefaultSettingsRequest;
use App\Repositories\ProgramDefaultSettingRepository;
use Illuminate\Http\JsonResponse;

/**
 * Platform program default settings (program_default_settings.site_id null). super_admin only (middleware).
 * Site admins use ProgramDefaultSettingsController with site-scoped rows.
 */
class ProgramPlatformDefaultSettingsController extends Controller
{
    public function __construct(
        private ProgramDefaultSettingRepository $programDefaultSettingRepository
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'settings' => $this->programDefaultSettingRepository->getPlatformTemplate(),
        ]);
    }

    public function update(UpdateProgramDefaultSettingsRequest $request): JsonResponse
    {
        $settings = $request->validated()['settings'];
        $normalized = $this->programDefaultSettingRepository->normalizeSettings($settings);
        $this->programDefaultSettingRepository->persistPlatformTemplate($normalized);

        return response()->json(['settings' => $normalized]);
    }
}
