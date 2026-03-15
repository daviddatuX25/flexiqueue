<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSiteSettingsRequest;
use Illuminate\Http\JsonResponse;

/**
 * PATCH /api/admin/site/settings — update site settings. Admin only.
 *
 * @deprecated Prefer updating site (including settings) via PUT /api/admin/sites/{site}
 *             and SiteController@update. This endpoint remains for backward compatibility
 *             and returns 200 OK without persisting; all settings are consolidated there.
 */
class SiteSettingsController extends Controller
{
    public function update(UpdateSiteSettingsRequest $request): JsonResponse
    {
        return response()->json(['message' => 'OK']);
    }
}
