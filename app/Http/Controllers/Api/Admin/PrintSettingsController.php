<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePrintSettingsRequest;
use App\Http\Requests\UploadPrintImageRequest;
use App\Models\PrintSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrintSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = PrintSetting::instance();

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
        $settings = PrintSetting::instance();
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

    /**
     * Upload an image for the token print template (logo/background).
     * Stores on the public disk and updates the singleton PrintSetting.
     */
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

        $settings = PrintSetting::instance();
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
