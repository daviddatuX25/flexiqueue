<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;

/**
 * Per public-site plan: hero image upload/delete for site landing. Path stored in site.settings.landing_hero_image_path.
 */
class SiteHeroImageController extends Controller
{
    private const DISK = 'public';

    private const DIR_PREFIX = 'site-assets';

    public function store(Request $request, Site $site): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        $file = $request->file('image');
        $ext = $file->getClientOriginalExtension() ?: 'jpg';
        $path = $file->storeAs(self::DIR_PREFIX.'/'.$site->id, 'hero.'.$ext, self::DISK);
        $settings = $site->settings ?? [];
        $settings['landing_hero_image_path'] = $path;
        $site->settings = $settings;
        $site->save();

        return response()->json([
            'url' => Storage::disk(self::DISK)->url($path),
        ]);
    }

    public function destroy(Site $site): JsonResponse
    {
        $settings = $site->settings ?? [];
        $path = $settings['landing_hero_image_path'] ?? null;
        if (is_string($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
        unset($settings['landing_hero_image_path']);
        $site->settings = $settings;
        $site->save();

        return response()->json(['message' => 'Deleted.']);
    }
}
