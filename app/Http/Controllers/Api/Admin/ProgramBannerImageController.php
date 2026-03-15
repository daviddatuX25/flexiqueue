<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;

/**
 * Per addition-to-public-site-plan Part 8.4: program banner image upload/delete.
 * Path stored in program.settings.page_banner_image_path.
 */
class ProgramBannerImageController extends Controller
{
    private const DISK = 'public';

    private const DIR_PREFIX = 'program-assets';

    public function store(Request $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        $file = $request->file('image');
        $ext = $file->getClientOriginalExtension() ?: 'jpg';
        $path = $file->storeAs(self::DIR_PREFIX.'/'.$program->id, 'banner.'.$ext, self::DISK);

        $settings = $program->settings ?? [];
        $settings['page_banner_image_path'] = $path;
        $program->settings = $settings;
        $program->save();

        return response()->json([
            'url' => Storage::disk(self::DISK)->url($path),
        ]);
    }

    public function destroy(Request $request, Program $program): JsonResponse
    {
        $this->ensureProgramInSite($request, $program);

        $settings = $program->settings ?? [];
        $path = $settings['page_banner_image_path'] ?? null;
        if (is_string($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
        unset($settings['page_banner_image_path']);
        $program->settings = $settings;
        $program->save();

        return response()->json(['message' => 'Deleted.']);
    }

    private function ensureProgramInSite(Request $request, Program $program): void
    {
        $siteId = $request->user()?->site_id;
        if ($siteId === null) {
            abort(403, 'You must be assigned to a site to access this resource.');
        }
        if ($program->site_id !== $siteId) {
            abort(404);
        }
    }
}
