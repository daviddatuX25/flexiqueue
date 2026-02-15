<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePrintSettingsRequest;
use App\Models\PrintSetting;
use Illuminate\Http\JsonResponse;

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
}
