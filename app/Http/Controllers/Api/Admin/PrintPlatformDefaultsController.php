<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePrintSettingsRequest;
use App\Http\Requests\UploadPrintImageRequest;
use App\Repositories\PrintSettingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Platform default print settings (print_settings.site_id null). super_admin only (middleware).
 * Site admins use PrintSettingsController with site-scoped rows.
 */
class PrintPlatformDefaultsController extends Controller
{
    public function __construct(
        private PrintSettingRepository $printSettingRepository
    ) {}

    public function show(Request $request): JsonResponse
    {
        $settings = $this->printSettingRepository->getPlatformTemplate();

        return response()->json([
            'print_settings' => [
                'cards_per_page' => $settings->cards_per_page,
                'paper' => $settings->paper,
                'orientation' => $settings->orientation,
                'show_hint' => $settings->show_hint,
                'show_cut_lines' => $settings->show_cut_lines,
                'logo_url' => $settings->logo_url,
                'footer_text' => $settings->footer_text,
                'bg_image_url' => $settings->bg_image_url,
            ],
        ]);
    }

    public function update(UpdatePrintSettingsRequest $request): JsonResponse
    {
        $settings = $this->printSettingRepository->getPlatformTemplate();
        $settings->update($request->validated());

        return response()->json([
            'print_settings' => [
                'cards_per_page' => $settings->cards_per_page,
                'paper' => $settings->paper,
                'orientation' => $settings->orientation,
                'show_hint' => $settings->show_hint,
                'show_cut_lines' => $settings->show_cut_lines,
                'logo_url' => $settings->logo_url,
                'footer_text' => $settings->footer_text,
                'bg_image_url' => $settings->bg_image_url,
            ],
        ]);
    }

    public function upload(UploadPrintImageRequest $request): JsonResponse
    {
        $file = $request->file('image');
        $type = $request->validated('type') ?? 'logo';

        $ext = $file->getClientOriginalExtension() ?: 'jpg';
        $filename = 'print_'.$type.'_'.Str::uuid()->toString().'.'.$ext;

        Storage::disk('public')->makeDirectory('print-settings');
        $path = $file->storeAs('print-settings', $filename, 'public');

        if (! Storage::disk('public')->exists($path)) {
            return response()->json(['message' => 'Image could not be stored.'], 500);
        }

        $url = Storage::disk('public')->url($path);

        $settings = $this->printSettingRepository->getPlatformTemplate();
        if ($type === 'background') {
            $settings->bg_image_url = $url;
        } else {
            $settings->logo_url = $url;
        }
        $settings->save();

        return response()->json([
            'url' => $url,
            'type' => $type,
        ], 201);
    }
}
